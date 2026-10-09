<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\MessageThread;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A teacher starting a conversation with a parent from the app.
 *
 * `/messages/send` takes a user id and nothing handed a teacher one, so the
 * app could only reply, never start. The recipients endpoint closes that, and
 * must reach only the families of children the teacher teaches.
 */
class TeacherMessagesParentTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private User $parent;
    private Student $taught;
    private Student $notTaught;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Message Academy',
            'slug' => 'msg',
            'subdomain' => 'msg',
            'domain' => 'msg.schoolpilot.test',
        ]);

        $this->teacher = $this->user('Mrs Teacher', 'teacher@msg.test', 'teacher');
        $this->parent = $this->user('Mr Parent', 'parent@msg.test', 'parent');

        $jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $maths = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);

        DB::table('teacher_subjects')->insert([
            'school_id' => $this->school->id,
            'teacher_id' => $this->teacher->id,
            'subject_id' => $maths->id,
            'class_id' => $jss1->id,
        ]);

        $this->taught = $this->student('Ada Taught', 'ADM1', $jss1->id);
        $this->notTaught = $this->student('Bayo Elsewhere', 'ADM2', $jss2->id);

        Guardian::create([
            'school_id' => $this->school->id,
            'user_id' => $this->parent->id,
            'full_name' => 'Mr Parent',
            'phone' => '08031111111',
            'relationship' => 'father',
        ]);
        $guardian = Guardian::allTenants()->where('user_id', $this->parent->id)->first();
        $this->taught->guardians()->attach($guardian->id);
        $this->notTaught->guardians()->attach($guardian->id);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function student(string $name, string $admission, int $classId): Student
    {
        return Student::create([
            'school_id' => $this->school->id,
            'user_id' => $this->user($name, strtolower($admission).'@msg.test', 'student')->id,
            'class_id' => $classId,
            'admission_number' => $admission,
            'status' => 'active',
        ]);
    }

    private function url(string $path): string
    {
        return 'http://msg.schoolpilot.test/api/v1'.$path;
    }

    public function test_teacher_gets_the_guardians_of_a_student_they_teach_without_contact_details()
    {
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson($this->url("/students/{$this->taught->id}/message-recipients"))
            ->assertOk()
            ->assertJsonPath('recipients.0.user_id', $this->parent->id)
            ->assertJsonPath('recipients.0.name', 'Mr Parent')
            ->assertJsonPath('recipients.0.relationship', 'father');

        // Messaging is in-app; the phone and email are not this endpoint's to give.
        $this->assertArrayNotHasKey('phone', $response->json('recipients.0'));
        $this->assertArrayNotHasKey('email', $response->json('recipients.0'));
    }

    public function test_teacher_cannot_list_the_family_of_a_student_they_do_not_teach()
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->getJson($this->url("/students/{$this->notTaught->id}/message-recipients"))
            ->assertForbidden();
    }

    public function test_parents_cannot_use_the_recipients_endpoint()
    {
        $this->actingAs($this->parent, 'sanctum')
            ->getJson($this->url("/students/{$this->taught->id}/message-recipients"))
            ->assertForbidden();
    }

    public function test_teacher_starts_a_conversation_about_a_student_they_teach()
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/messages/send'), [
                'recipient_id' => $this->parent->id,
                'student_id' => $this->taught->id,
                'subject' => 'Homework',
                'body' => 'Ada did very well this week.',
            ])
            ->assertCreated();

        $thread = MessageThread::first();
        $this->assertSame($this->taught->id, (int) $thread->student_id);
        $this->assertSame($this->parent->id, (int) $thread->parent_id);
    }

    public function test_a_conversation_about_a_student_must_be_with_that_students_guardian()
    {
        $stranger = $this->user('Other Parent', 'other@msg.test', 'parent');

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/messages/send'), [
                'recipient_id' => $stranger->id,
                'student_id' => $this->taught->id,
                'body' => 'About Ada.',
            ])
            ->assertStatus(422);

        $this->assertSame(0, MessageThread::count());
    }

    public function test_teacher_cannot_open_a_conversation_about_a_student_they_do_not_teach()
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/messages/send'), [
                'recipient_id' => $this->parent->id,
                'student_id' => $this->notTaught->id,
                'body' => 'About Bayo.',
            ])
            ->assertForbidden();
    }
    /** The app's reply sends the thread and the text, not a recipient. */
    public function test_a_reply_needs_only_the_thread()
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson($this->url('/messages/send'), [
                'recipient_id' => $this->parent->id,
                'body' => 'Hello.',
            ])
            ->assertCreated();

        $thread = MessageThread::first();

        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/messages/send'), [
                'thread_id' => $thread->id,
                'body' => 'Thank you, ma.',
            ])
            ->assertCreated();
    }
}
