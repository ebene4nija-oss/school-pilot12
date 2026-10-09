<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\CbtExam;
use App\Models\LeaveRequest;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The administrator's approvals inbox: one read across leave, lesson notes,
 * CBT papers, admissions and broadsheets — pending items only, this school's.
 */
class ApprovalsInboxTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = $this->school('Inbox Academy', 'inbox');
        $this->admin = $this->user('Admin', 'school_admin', $this->school);
        $this->teacher = $this->user('Mrs Okafor', 'teacher', $this->school);
    }

    private function school(string $name, string $sub): School
    {
        return School::create(['name' => $name, 'slug' => $sub, 'subdomain' => $sub, 'domain' => "{$sub}.schoolpilot.test"]);
    }

    private function user(string $name, string $role, School $school): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'.'.$school->id.'@inbox.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $school->id, 'role' => $role]);

        return $u;
    }

    private function url(string $sub = 'inbox'): string
    {
        return "http://{$sub}.schoolpilot.test/api/v1/approvals";
    }

    private function seedPending(School $school, User $teacher): void
    {
        $staff = Staff::create(['school_id' => $school->id, 'user_id' => $teacher->id, 'staff_id' => 'ST'.$school->id, 'designation' => 'Teacher']);
        LeaveRequest::create(['school_id' => $school->id, 'staff_id' => $staff->id, 'leave_type' => 'sick', 'start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'days_requested' => 2, 'reason' => 'Malaria', 'status' => 'pending']);
        // Decided already: must not appear.
        LeaveRequest::create(['school_id' => $school->id, 'staff_id' => $staff->id, 'leave_type' => 'annual', 'start_date' => '2026-12-01', 'end_date' => '2026-12-05', 'days_requested' => 5, 'status' => 'approved']);

        $class = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 2']);
        $subject = Subject::create(['school_id' => $school->id, 'name' => 'Mathematics', 'code' => 'MTH'.$school->id]);

        DB::table('learning_materials')->insert([
            ['school_id' => $school->id, 'uploaded_by' => $teacher->id, 'title' => 'Fractions notes', 'type' => 'pdf', 'status' => 'pending_approval', 'class_id' => $class->id, 'subject_id' => $subject->id, 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $school->id, 'uploaded_by' => $teacher->id, 'title' => 'Already shared', 'type' => 'pdf', 'status' => 'published', 'class_id' => $class->id, 'subject_id' => $subject->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        CbtExam::create(['school_id' => $school->id, 'subject_id' => $subject->id, 'class_id' => $class->id, 'title' => 'Maths mid-term', 'duration_minutes' => 40, 'status' => 'draft', 'approval_status' => 'pending', 'created_by' => $teacher->id]);

        DB::table('admission_applications')->insert([
            ['school_id' => $school->id, 'reference' => 'ADM-'.$school->id.'-1', 'section' => 'secondary', 'applicant_name' => 'Tobi Ade', 'intended_class_id' => $class->id, 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $school->id, 'reference' => 'ADM-'.$school->id.'-2', 'section' => 'secondary', 'applicant_name' => 'Decided Already', 'intended_class_id' => $class->id, 'status' => 'offered', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $session = AcademicSession::create(['school_id' => $school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(10), 'is_current' => true]);
        $term = Term::create(['school_id' => $school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonth()]);
        DB::table('broadsheets')->insert([
            'school_id' => $school->id, 'term_id' => $term->id, 'class_id' => $class->id, 'reference' => 'BS-'.$school->id,
            'status' => 'in_review', 'steps' => json_encode([['label' => 'Principal approval']]), 'current_step' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_admin_sees_every_pending_item_and_nothing_decided(): void
    {
        $this->seedPending($this->school, $this->teacher);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk();

        $response->assertJsonPath('total', 5)
            ->assertJsonPath('counts.leave', 1)
            ->assertJsonPath('counts.lesson_note', 1)
            ->assertJsonPath('counts.cbt_exam', 1)
            ->assertJsonPath('counts.admission', 1)
            ->assertJsonPath('counts.broadsheet', 1);

        $titles = collect($response->json('items'))->pluck('title');
        $this->assertTrue($titles->contains('Mrs Okafor — sick leave'));
        $this->assertTrue($titles->contains('Fractions notes'));
        $this->assertTrue($titles->contains('Maths mid-term'));
        $this->assertTrue($titles->contains('Tobi Ade'));
        $this->assertFalse($titles->contains('Already shared'));
        $this->assertFalse($titles->contains('Decided Already'));

        $broadsheet = collect($response->json('items'))->firstWhere('type', 'broadsheet');
        $this->assertSame('Waiting at: Principal approval', $broadsheet['detail']);
    }

    public function test_another_schools_items_never_appear(): void
    {
        $other = $this->school('Other School', 'other');
        $this->seedPending($other, $this->user('Other Teacher', 'teacher', $other));

        $this->actingAs($this->admin, 'sanctum')->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_teachers_cannot_read_the_inbox(): void
    {
        $this->actingAs($this->teacher, 'sanctum')->getJson($this->url())->assertForbidden();
    }
}
