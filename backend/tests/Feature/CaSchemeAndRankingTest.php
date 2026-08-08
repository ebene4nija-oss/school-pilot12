<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\CaScheme;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Doc §7.7: CA weighting is per-school and configurable, and results carry a
 * class position. Both were missing — scoring was hardcoded to 20/20/60 and
 * `position_in_class` was never written.
 */
class CaSchemeAndRankingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private Term $term;
    private SchoolClass $class;
    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Hilltop College',
            'slug' => 'hilltop',
            'subdomain' => 'hilltop',
            'domain' => 'hilltop.schoolpilot.test',
        ]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@hilltop.test',
            'password' => bcrypt('password123'),
        ]);
        $this->admin->userProfile()->create(['school_id' => $this->school->id, 'role' => 'school_admin']);

        $session = AcademicSession::create([
            'school_id' => $this->school->id,
            'name' => '2025/2026',
            'start_date' => now(),
            'end_date' => now()->addYear(),
        ]);

        $this->term = Term::create([
            'school_id' => $this->school->id,
            'session_id' => $session->id,
            'name' => 'First Term',
            'start_date' => now(),
            'end_date' => now()->addMonths(3),
        ]);

        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics']);
    }

    private function student(string $name, string $admission): Student
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@hilltop.test',
            'password' => bcrypt('password123'),
        ]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => 'student']);

        return Student::create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'class_id' => $this->class->id,
            'admission_number' => $admission,
        ]);
    }

    private function postScore(Student $student, array $marks)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('http://hilltop.schoolpilot.test/api/v1/assessment/score', array_merge([
                'term_id' => $this->term->id,
                'student_id' => $student->id,
                'subject_id' => $this->subject->id,
            ], $marks));
    }

    /** With no scheme configured a school still marks on the Nigerian default. */
    public function test_defaults_to_twenty_twenty_sixty_when_no_scheme_configured()
    {
        $student = $this->student('Ada Obi', 'ADM001');

        $this->postScore($student, ['first_ca' => 20, 'second_ca' => 20, 'exam' => 60])
            ->assertStatus(200)
            ->assertJsonPath('scheme.exam_weight', 60);

        $this->assertSame(100.0, $this->storedTotal($student));
    }

    /**
     * The bug this whole item exists for: a school on 30/30/40 could not enter
     * a 25-mark CA, because the rule was `max:20` regardless of scheme.
     */
    public function test_school_on_thirty_thirty_forty_can_enter_a_twenty_five_mark_ca()
    {
        CaScheme::create([
            'school_id' => $this->school->id,
            'name' => 'Continuous 30/30/40',
            'first_ca_weight' => 30,
            'second_ca_weight' => 30,
            'exam_weight' => 40,
            'is_default' => true,
        ]);

        $student = $this->student('Bola Ade', 'ADM002');

        $this->postScore($student, ['first_ca' => 25, 'second_ca' => 28, 'exam' => 35])
            ->assertStatus(200)
            ->assertJsonPath('scheme.first_ca_weight', 30);

        $this->assertSame(88.0, $this->storedTotal($student));
    }

    /** A mark above the scheme's ceiling is still refused, with a useful message. */
    public function test_mark_above_the_schemes_ceiling_is_rejected()
    {
        CaScheme::create([
            'school_id' => $this->school->id,
            'name' => 'Continuous 30/30/40',
            'first_ca_weight' => 30,
            'second_ca_weight' => 30,
            'exam_weight' => 40,
            'is_default' => true,
        ]);

        $student = $this->student('Chidi Eze', 'ADM003');

        $this->postScore($student, ['first_ca' => 31, 'second_ca' => 10, 'exam' => 10])
            ->assertStatus(422)
            ->assertJsonPath('errors.first_ca.0', "First CA is marked out of 30 under the 'Continuous 30/30/40' scheme.");
    }

    /** A scheme totalling 60 is normalised to a percentage, not left as raw marks. */
    public function test_scheme_not_summing_to_one_hundred_is_normalised()
    {
        CaScheme::create([
            'school_id' => $this->school->id,
            'name' => 'Out of 60',
            'first_ca_weight' => 10,
            'second_ca_weight' => 10,
            'exam_weight' => 40,
            'is_default' => true,
        ]);

        $student = $this->student('Dupe Sanni', 'ADM004');

        // 30 raw out of 60 == 50%.
        $this->postScore($student, ['first_ca' => 5, 'second_ca' => 5, 'exam' => 20])
            ->assertStatus(200)
            ->assertJsonPath('score_entry.grade', 'C6');

        $this->assertSame(50.0, $this->storedTotal($student));
    }

    /** Decimal columns serialise as float or string depending on refresh
     *  timing, so assert the persisted value rather than its JSON shape. */
    private function storedTotal(Student $student): float
    {
        return (float) ScoreEntry::where('student_id', $student->id)
            ->where('term_id', $this->term->id)
            ->first()
            ->total_score;
    }

    /** §7.7 class ranking — the column used to stay null forever. */
    public function test_class_positions_are_computed_and_persisted()
    {
        $top = $this->student('Top Student', 'ADM010');
        $middle = $this->student('Middle Student', 'ADM011');
        $bottom = $this->student('Bottom Student', 'ADM012');

        $this->postScore($top, ['first_ca' => 20, 'second_ca' => 20, 'exam' => 55]);     // 95
        $this->postScore($middle, ['first_ca' => 15, 'second_ca' => 15, 'exam' => 40]);  // 70
        $this->postScore($bottom, ['first_ca' => 10, 'second_ca' => 10, 'exam' => 25]);  // 45

        $this->assertSame(1, ScoreEntry::where('student_id', $top->id)->first()->position_in_class);
        $this->assertSame(2, ScoreEntry::where('student_id', $middle->id)->first()->position_in_class);
        $this->assertSame(3, ScoreEntry::where('student_id', $bottom->id)->first()->position_in_class);
    }

    /**
     * Two children on the same average are both 1st, and the next is 3rd.
     * Reporting the third child as 2nd when two scored higher is the kind of
     * detail a parent brings to the principal.
     */
    public function test_tied_students_share_a_position_and_the_next_skips()
    {
        $a = $this->student('Tied One', 'ADM020');
        $b = $this->student('Tied Two', 'ADM021');
        $c = $this->student('Third Place', 'ADM022');

        $this->postScore($a, ['first_ca' => 20, 'second_ca' => 20, 'exam' => 40]); // 80
        $this->postScore($b, ['first_ca' => 20, 'second_ca' => 20, 'exam' => 40]); // 80
        $this->postScore($c, ['first_ca' => 10, 'second_ca' => 10, 'exam' => 30]); // 50

        $this->assertSame(1, ScoreEntry::where('student_id', $a->id)->first()->position_in_class);
        $this->assertSame(1, ScoreEntry::where('student_id', $b->id)->first()->position_in_class);
        $this->assertSame(3, ScoreEntry::where('student_id', $c->id)->first()->position_in_class);
    }

    public function test_admin_can_create_and_activate_a_scheme()
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('http://hilltop.schoolpilot.test/api/v1/assessment/ca-schemes', [
                'name' => 'Cambridge 40/60',
                'first_ca_weight' => 20,
                'second_ca_weight' => 20,
                'exam_weight' => 60,
            ])
            ->assertStatus(201);

        $second = CaScheme::create([
            'school_id' => $this->school->id,
            'name' => 'Heavy CA',
            'first_ca_weight' => 30,
            'second_ca_weight' => 30,
            'exam_weight' => 40,
            'is_default' => false,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("http://hilltop.schoolpilot.test/api/v1/assessment/ca-schemes/{$second->id}/activate")
            ->assertStatus(200);

        $this->assertTrue(CaScheme::find($second->id)->is_default);
        $this->assertSame('Heavy CA', CaScheme::activeFor($this->school->id)->name);
    }

    public function test_a_scheme_with_zero_total_weight_is_rejected()
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('http://hilltop.schoolpilot.test/api/v1/assessment/ca-schemes', [
                'name' => 'Broken',
                'first_ca_weight' => 0,
                'second_ca_weight' => 0,
                'exam_weight' => 0,
            ])
            ->assertStatus(422);
    }
}
