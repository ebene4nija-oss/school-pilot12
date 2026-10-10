<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\ClassTeacherAssignment;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Editing a student's record after enrolment, and private passport photos
 * (doc §12): stored off the public disk, served only to those who may see
 * the student, taken by an admin or the class's form teacher.
 */
class StudentRecordEditTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private SchoolClass $class;
    private Student $student;
    private string $host = 'http://hilltop.localhost/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop']);
        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $this->user('Ada Obi', 'student')->id,
            'class_id' => $this->class->id,
            'admission_number' => 'HT/001',
            'status' => 'active',
        ]);
    }

    private function user(string $name, string $role, ?School $school = null): User
    {
        $u = User::factory()->create(['name' => $name]);
        UserProfile::create(['school_id' => ($school ?? $this->school)->id, 'user_id' => $u->id, 'role' => $role]);

        return $u;
    }

    private function png(): UploadedFile
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAYAAACp8Z5+AAAAFUlEQVR42mNkYPhfz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC');

        return UploadedFile::fake()->createWithContent('passport.png', $bytes);
    }

    private function formTeacher(): User
    {
        $teacher = $this->user('Mrs Bello', 'teacher');
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now(), 'end_date' => now()->addYear(), 'is_current' => true]);
        ClassTeacherAssignment::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'class_id' => $this->class->id, 'form_teacher_id' => $teacher->id]);

        return $teacher;
    }

    public function test_an_admin_corrects_a_students_record_and_it_is_audited()
    {
        $admin = $this->user('Principal', 'school_admin');

        $this->actingAs($admin, 'sanctum')->putJson("{$this->host}/students/{$this->student->id}", [
            'name' => 'Adaeze Obi', 'gender' => 'female', 'date_of_birth' => '2014-03-02', 'state_of_origin' => 'Anambra',
        ])->assertOk();

        $fresh = $this->student->fresh(['user']);
        $this->assertSame('Adaeze Obi', $fresh->user->name);
        $this->assertSame('female', $fresh->gender);
        $this->assertSame('Anambra', $fresh->state_of_origin);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.updated', 'auditable_id' => $this->student->id]);
    }

    public function test_a_teacher_cannot_rewrite_a_record()
    {
        $teacher = $this->user('Mr Ade', 'teacher');

        $this->actingAs($teacher, 'sanctum')->putJson("{$this->host}/students/{$this->student->id}", ['name' => 'Someone Else'])
            ->assertForbidden();
        $this->assertSame('Ada Obi', $this->student->fresh(['user'])->user->name);
    }

    public function test_a_class_from_another_school_is_refused()
    {
        $admin = $this->user('Principal', 'school_admin');
        $other = School::create(['name' => 'Riverside', 'slug' => 'riverside', 'subdomain' => 'riverside']);
        $theirs = SchoolClass::create(['school_id' => $other->id, 'name' => 'JSS 1']);

        $this->actingAs($admin, 'sanctum')->putJson("{$this->host}/students/{$this->student->id}", ['class_id' => $theirs->id])
            ->assertStatus(422);
        $this->assertSame($this->class->id, $this->student->fresh()->class_id);
    }

    public function test_a_photo_is_stored_privately_and_served_only_to_those_allowed()
    {
        $admin = $this->user('Principal', 'school_admin');

        $this->actingAs($admin, 'sanctum')->post("{$this->host}/students/{$this->student->id}/photo", ['photo' => $this->png()])
            ->assertOk();

        $path = $this->student->fresh()->passport_photo_path;
        $this->assertStringStartsWith('private/students/photos/', $path);
        Storage::disk('local')->assertExists(substr($path, strlen('private/')));
        $this->assertEmpty(Storage::disk('public')->allFiles(), 'Nothing on the public disk.');

        // The child's guardian may see it; another parent may not; nobody signed out may.
        $parent = $this->user('Mrs Obi', 'parent');
        $guardian = Guardian::create(['school_id' => $this->school->id, 'user_id' => $parent->id, 'relationship' => 'mother']);
        $guardian->students()->attach($this->student->id, ['is_primary' => true]);
        $stranger = $this->user('Mr Nobody', 'parent');

        $this->actingAs($parent, 'sanctum')->get("{$this->host}/students/{$this->student->id}/photo")->assertOk();
        $this->actingAs($stranger, 'sanctum')->get("{$this->host}/students/{$this->student->id}/photo")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson("{$this->host}/students/{$this->student->id}/photo")->assertUnauthorized();

        // Listed with a URL to the protected route, never a public link.
        $this->actingAs($admin, 'sanctum')->getJson("{$this->host}/students/{$this->student->id}")
            ->assertJsonPath('student.photo_url', url("/api/v1/students/{$this->student->id}/photo"));
    }

    public function test_the_form_teacher_may_take_the_photo_but_another_teacher_may_not()
    {
        $form = $this->formTeacher();
        $other = $this->user('Mr Ade', 'teacher');

        $this->actingAs($other, 'sanctum')->post("{$this->host}/students/{$this->student->id}/photo", ['photo' => $this->png()])
            ->assertForbidden();
        $this->actingAs($form, 'sanctum')->post("{$this->host}/students/{$this->student->id}/photo", ['photo' => $this->png()])
            ->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.photo_updated', 'user_id' => $form->id]);
    }

    public function test_the_move_command_takes_old_public_photos_private()
    {
        Storage::disk('public')->put('students/avatars/old.png', $this->png()->getContent());
        $this->student->update(['passport_photo_path' => 'students/avatars/old.png']);

        $this->artisan('students:privatise-photos')->assertSuccessful();

        $path = $this->student->fresh()->passport_photo_path;
        $this->assertStringStartsWith('private/', $path);
        Storage::disk('public')->assertMissing('students/avatars/old.png');
        Storage::disk('local')->assertExists(substr($path, strlen('private/')));
    }
}
