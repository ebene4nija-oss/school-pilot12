<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\BroadsheetConfiguration;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\Results\ResultCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Streams, merit arms and subjects that belong to a level.
 *
 * SS 2A (science) and SS 2B (arts) share a class but not a subject list; a
 * Nursery 1 picker must never offer Physics; and positions can be taken
 * within the arm or the stream rather than across the whole class.
 */
class StreamsAndSubjectOfferingTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private Term $term;
    private SchoolClass $ss2;
    private Arm $science;
    private Arm $arts;
    private Subject $english;
    private Subject $physics;
    private Subject $literature;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Kings College', 'slug' => 'kings', 'subdomain' => 'kings']);
        $this->admin = $this->user('Principal', 'school_admin');

        $session = AcademicSession::create([
            'school_id' => $this->school->id, 'name' => '2026/2027',
            'start_date' => now()->subMonth(), 'end_date' => now()->addYear(),
        ]);
        $this->term = Term::create([
            'school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term',
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2),
        ]);

        $this->ss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'SS 2', 'level_category' => 'senior_secondary']);
        $this->science = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->ss2->id, 'name' => 'A', 'stream' => 'science']);
        $this->arts = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->ss2->id, 'name' => 'B', 'stream' => 'arts']);

        $this->english = Subject::create(['school_id' => $this->school->id, 'name' => 'English Language']);
        $this->physics = Subject::create(['school_id' => $this->school->id, 'name' => 'Physics', 'level_groups' => ['senior_secondary']]);
        $this->literature = Subject::create(['school_id' => $this->school->id, 'name' => 'Literature in English', 'level_groups' => ['senior_secondary']]);

        $this->as($this->admin)->postJson('/api/v1/classes/subjects', [
            'class_id' => $this->ss2->id,
            'subjects' => [
                ['subject_id' => $this->english->id, 'is_compulsory' => true],
                ['subject_id' => $this->physics->id, 'is_compulsory' => true, 'streams' => ['science']],
                ['subject_id' => $this->literature->id, 'is_compulsory' => true, 'streams' => ['arts']],
            ],
        ])->assertOk();
    }

    private function user(string $name, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => str_replace(' ', '.', strtolower($name)).'@kings.test', 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum')->withServerVariables(['HTTP_HOST' => 'kings.localhost']);
    }

    private function student(string $name, Arm $arm): Student
    {
        return Student::create([
            'school_id' => $this->school->id, 'user_id' => $this->user($name, 'student')->id,
            'class_id' => $this->ss2->id, 'arm_id' => $arm->id, 'admission_number' => 'K'.random_int(1000, 99999),
        ]);
    }

    private function score(Student $student, Subject $subject, float $total): void
    {
        DB::table('score_entries')->insert([
            'school_id' => $this->school->id, 'term_id' => $this->term->id, 'student_id' => $student->id,
            'subject_id' => $subject->id, 'total_score' => $total, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------- Classes and arms

    public function test_an_admin_sets_up_a_class_and_a_streamed_merit_arm()
    {
        $class = $this->as($this->admin)->postJson('/api/v1/classes', ['name' => 'SS 1', 'level_category' => 'senior_secondary', 'order_index' => 4])
            ->assertCreated()->json('data');

        $this->as($this->admin)->postJson("/api/v1/classes/{$class['id']}/arms", ['name' => 'Gold', 'stream' => 'science', 'is_merit' => true])
            ->assertCreated()->assertJsonPath('data.stream', 'science');

        $this->as($this->admin)->postJson("/api/v1/classes/{$class['id']}/arms", ['name' => 'Gold'])->assertStatus(422);
        $this->as($this->admin)->postJson("/api/v1/classes/{$class['id']}/arms", ['name' => 'Blue', 'stream' => 'faculty'])->assertStatus(422);
        $this->as($this->admin)->postJson('/api/v1/classes', ['name' => 'SS 1', 'level_category' => 'senior_secondary'])->assertStatus(422);

        $teacher = $this->user('Mr Bello', 'teacher');
        $this->as($teacher)->postJson('/api/v1/classes', ['name' => 'SS 3', 'level_category' => 'senior_secondary'])->assertForbidden();
    }

    public function test_only_staff_see_which_arm_is_the_merit_arm()
    {
        $this->science->update(['is_merit' => true]);

        $staffArm = collect($this->as($this->admin)->getJson('/api/v1/classes')->assertOk()->json('data.0.arms'))->firstWhere('name', 'A');
        $this->assertTrue($staffArm['is_merit']);
        $this->assertSame('science', $staffArm['stream']);

        $pupil = $this->student('Ada Obi', $this->science);
        $pupilArm = collect($this->as($pupil->user)->getJson('/api/v1/classes')->assertOk()->json('data.0.arms'))->firstWhere('name', 'A');
        $this->assertArrayNotHasKey('is_merit', $pupilArm);
    }

    public function test_an_arm_with_students_cannot_be_deleted()
    {
        $this->student('Ada Obi', $this->science);

        $this->as($this->admin)->deleteJson("/api/v1/arms/{$this->science->id}")->assertStatus(409);
        $this->as($this->admin)->deleteJson("/api/v1/classes/{$this->ss2->id}")->assertStatus(409);

        $empty = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->ss2->id, 'name' => 'C']);
        $this->as($this->admin)->deleteJson("/api/v1/arms/{$empty->id}")->assertOk();
    }

    // --------------------------------------------------------- Subject lists

    public function test_each_arm_gets_its_streams_subjects()
    {
        $names = fn ($arm) => collect($this->as($this->admin)->getJson("/api/v1/subjects?class_id={$this->ss2->id}&arm_id={$arm->id}")
            ->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['English Language', 'Physics'], $names($this->science));
        $this->assertSame(['English Language', 'Literature in English'], $names($this->arts));

        // The whole class: everything it offers.
        $this->assertCount(3, $this->as($this->admin)->getJson("/api/v1/subjects?class_id={$this->ss2->id}")->json('data'));
    }

    public function test_a_class_with_no_subjects_set_falls_back_to_its_level()
    {
        $nursery = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'Nursery 1', 'level_category' => 'nursery']);

        $names = collect($this->as($this->admin)->getJson("/api/v1/subjects?class_id={$nursery->id}")->assertOk()->json('data'))->pluck('name');

        $this->assertContains('English Language', $names); // untagged: every level
        $this->assertNotContains('Physics', $names);

        $this->as($this->admin)->putJson("/api/v1/subjects/{$this->english->id}", ['level_groups' => ['secondary']])->assertOk();
        $this->assertNotContains('English Language', collect($this->as($this->admin)->getJson('/api/v1/subjects?level_group=nursery')->json('data'))->pluck('name'));
        $this->assertContains('English Language', collect($this->as($this->admin)->getJson('/api/v1/subjects?level_group=junior_secondary')->json('data'))->pluck('name'));
    }

    public function test_a_student_cannot_be_enrolled_in_another_streams_subject()
    {
        $pupil = $this->student('Bisi Ade', $this->arts);

        $this->as($this->admin)->postJson("/api/v1/students/{$pupil->id}/subjects", ['term_id' => $this->term->id, 'subject_ids' => [$this->physics->id]])
            ->assertStatus(422)->assertJsonPath('invalid_subject_ids', [$this->physics->id]);

        $this->as($this->admin)->postJson('/api/v1/subjects/bulk-auto-enroll', ['class_id' => $this->ss2->id, 'term_id' => $this->term->id])->assertOk();

        $taken = DB::table('student_subjects')->where('student_id', $pupil->id)->pluck('subject_id')->sort()->values()->all();
        $this->assertSame([$this->english->id, $this->literature->id], $taken);
    }

    // ------------------------------------------------------ Results and ranks

    public function test_another_streams_subject_is_not_missing_for_a_student()
    {
        $ada = $this->student('Ada Obi', $this->science);
        $this->score($ada, $this->english, 70);
        $this->score($ada, $this->physics, 80);
        // Literature is on the broadsheet because the arts arm takes it.
        $this->student('Bisi Ade', $this->arts);

        $row = collect(app(ResultCalculator::class)->forClass($this->school->id, $this->term->id, $this->ss2->id)['students'])->firstWhere('id', $ada->id);

        $this->assertTrue($row['complete']);
        $this->assertSame('not_offered', $row['results'][$this->literature->id]['state']);
        $this->assertSame('science', $row['stream']);
    }

    public function test_positions_follow_the_ranking_group()
    {
        $a1 = $this->student('Ada Obi', $this->science);
        $a2 = $this->student('Chidi Eze', $this->science);
        $b1 = $this->student('Bisi Ade', $this->arts);

        $this->score($a1, $this->english, 90);
        $this->score($a2, $this->english, 80);
        $this->score($b1, $this->english, 70);

        $rank = function (?string $group) {
            BroadsheetConfiguration::updateOrCreate(['school_id' => $this->school->id, 'level_group' => null], ['ranking_group' => $group]);
            $rows = collect(app(ResultCalculator::class)->forClass($this->school->id, $this->term->id, $this->ss2->id)['students'])->keyBy('id');

            return $rows->map(fn ($r) => [$r['position'], $r['ranked_among']])->all();
        };

        $byClass = $rank('class');
        $this->assertSame([3, 3], $byClass[$b1->id]);

        $byArm = $rank('arm');
        $this->assertSame([1, 2], $byArm[$a1->id]);
        $this->assertSame([2, 2], $byArm[$a2->id]);
        $this->assertSame([1, 1], $byArm[$b1->id]);

        $this->assertSame([1, 1], $rank('stream')[$b1->id]);
    }

    public function test_a_school_with_no_choice_uses_its_default_ranking_group()
    {
        // New schools rank within the arm; schools that existed before keep the class.
        $this->assertSame('arm', BroadsheetConfiguration::resolve($this->school->id, 'senior_secondary')['ranking_group']);

        DB::table('schools')->where('id', $this->school->id)->update(['default_ranking_group' => 'class']);
        $this->assertSame('class', BroadsheetConfiguration::resolve($this->school->id, 'senior_secondary')['ranking_group']);

        $this->as($this->admin)->putJson('/api/v1/broadsheets/config', ['level_group' => 'senior_secondary', 'ranking_group' => 'stream'])->assertOk();
        $this->assertSame('stream', BroadsheetConfiguration::resolve($this->school->id, 'senior_secondary')['ranking_group']);
    }
}
