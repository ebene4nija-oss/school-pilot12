<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Everyone taking a subject writes its paper together — in a named hall or
 * each arm in its own classroom — and a student sees only their own papers.
 */
class ExamHallsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private Term $term;
    private SchoolClass $ss2;
    private Arm $science;
    private Arm $arts;
    private Subject $physics;
    private Subject $english;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Kings', 'slug' => 'kings', 'subdomain' => 'kings']);
        $this->admin = $this->user('school_admin');
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(10)]);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2)]);

        $this->ss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'SS 2', 'level_category' => 'senior_secondary']);
        $this->science = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->ss2->id, 'name' => 'A', 'stream' => 'science']);
        $this->arts = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->ss2->id, 'name' => 'B', 'stream' => 'arts']);
        $this->physics = Subject::create(['school_id' => $this->school->id, 'name' => 'Physics']);
        $this->english = Subject::create(['school_id' => $this->school->id, 'name' => 'English']);

        DB::table('class_subjects')->insert([
            ['school_id' => $this->school->id, 'class_id' => $this->ss2->id, 'subject_id' => $this->physics->id, 'is_compulsory' => true, 'streams' => json_encode(['science']), 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $this->school->id, 'class_id' => $this->ss2->id, 'subject_id' => $this->english->id, 'is_compulsory' => true, 'streams' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => ucfirst($role).' '.++$this->n, 'email' => "{$role}{$this->n}@kings.test", 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function pupil(Arm $arm): Student
    {
        return Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('student')->id, 'class_id' => $this->ss2->id, 'arm_id' => $arm->id, 'admission_number' => 'K'.$this->n, 'status' => 'active']);
    }

    private function as(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum')->withServerVariables(['HTTP_HOST' => 'kings.localhost']);
    }

    private function timetable(): int
    {
        return $this->as($this->admin)->postJson('/api/v1/exam-timetables', ['term_id' => $this->term->id, 'name' => 'First Term Exams'])->assertCreated()->json('data.id');
    }

    private function paper(int $timetableId, Subject $subject, array $where, string $start = '09:00')
    {
        return $this->as($this->admin)->postJson("/api/v1/exam-timetables/{$timetableId}/sittings", [
            'class_id' => $this->ss2->id, 'subject_id' => $subject->id, 'exam_date' => now()->addWeek()->format('Y-m-d'),
            'start_time' => $start, 'end_time' => date('H:i', strtotime($start) + 7200),
        ] + $where);
    }

    public function test_a_paper_in_a_hall_is_written_by_everyone_taking_the_subject()
    {
        $this->pupil($this->science);
        $this->pupil($this->science);
        $this->pupil($this->arts);
        $hall = Venue::create(['school_id' => $this->school->id, 'name' => 'Main Hall', 'kind' => 'hall', 'capacity' => 1]);

        $res = $this->paper($this->timetable(), $this->physics, ['venue_mode' => 'hall', 'venue_id' => $hall->id])->assertCreated();

        $res->assertJsonPath('data.venue', 'Main Hall')->assertJsonPath('data.candidates', 2);
        $this->assertStringContainsString('Main Hall seats 1 but 2 candidates', $res->json('message'));

        $who = $this->as($this->admin)->getJson('/api/v1/exam-sittings/'.$res->json('data.id').'/candidates')->assertOk();
        $who->assertJsonPath('total', 2);
        $this->assertSame(['A'], collect($who->json('by_arm'))->pluck('arm')->all());
    }

    public function test_own_classrooms_needs_no_hall()
    {
        $hall = Venue::create(['school_id' => $this->school->id, 'name' => 'Main Hall', 'kind' => 'hall']);

        $this->paper($this->timetable(), $this->english, ['venue_mode' => 'own_classrooms', 'venue_id' => $hall->id])
            ->assertCreated()
            ->assertJsonPath('data.venue_mode', 'own_classrooms')
            ->assertJsonPath('data.venue_id', null)
            ->assertJsonPath('data.venue', 'Own classroom');
    }

    public function test_a_student_sees_only_the_papers_they_write()
    {
        $scientist = $this->pupil($this->science);
        $artist = $this->pupil($this->arts);
        $id = $this->timetable();
        $this->paper($id, $this->physics, ['venue' => 'Science Block'])->assertCreated();
        $this->paper($id, $this->english, ['venue_mode' => 'own_classrooms'], '12:00')->assertCreated();
        $this->as($this->admin)->putJson("/api/v1/exam-timetables/{$id}", ['status' => 'published'])->assertOk();

        $subjects = fn (Student $s) => collect($this->as($s->user)->getJson("/api/v1/exam-timetables/{$id}")->assertOk()->json('sittings'))->pluck('subject')->all();

        $this->assertSame(['Physics', 'English'], $subjects($scientist));
        $this->assertSame(['English'], $subjects($artist));
    }
}
