<?php

namespace Tests\Feature;

use App\Models\Arm;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Getting people into a school: students with their parents, staff one at a
 * time or by CSV, and the nurse role.
 *
 * The enrolment form collected four fields and no class or parent; there was
 * no way to add staff from the portal at all; and the importer invented email
 * addresses for anyone without one, which email broadcasts would now try to
 * deliver to.
 */
class EnrolmentAndStaffTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $rival;

    private User $admin;

    private SchoolClass $jss1;

    private SchoolClass $jss2;

    private Arm $gold;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->school = School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield', 'status' => 'active']);
        $this->rival = School::create(['name' => 'Rival', 'slug' => 'rival', 'subdomain' => 'rival', 'status' => 'active']);

        $this->admin = $this->person('Head Admin', 'admin@greenfield.test', 'school_admin');

        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);
        $this->gold = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'name' => 'Gold']);
    }

    private function person(string $name, ?string $email, string $role, ?School $school = null): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => ($school ?? $this->school)->id, 'role' => $role]);

        return $user;
    }

    private function enrol(array $overrides = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/students', array_merge([
            'name' => 'Ada Okafor',
            'gender' => 'female',
            'class_id' => $this->jss1->id,
            'arm_id' => $this->gold->id,
            'date_of_birth' => '2013-05-02',
            'state_of_origin' => 'Anambra',
            'lga' => 'Onitsha North',
            'guardians' => [
                ['name' => 'Mrs Okafor', 'phone' => '0803 123 4567', 'relationship' => 'mother'],
            ],
        ], $overrides));
    }

    // ------------------------------------------------------------ enrolment

    /** The whole record in one request — class, arm, parent — with no email anywhere. */
    public function test_a_student_is_enrolled_with_class_and_parent_and_no_email(): void
    {
        $response = $this->enrol()->assertCreated();

        $student = Student::withoutGlobalScopes()->findOrFail($response->json('student.id'));

        $this->assertSame($this->jss1->id, $student->class_id);
        $this->assertSame($this->gold->id, $student->arm_id);
        $this->assertNull($student->user->email);
        $this->assertNotEmpty($student->admission_number);

        $guardian = $student->guardians()->with('user.userProfile')->first();
        $this->assertSame('Mrs Okafor', $guardian->user->name);
        $this->assertNull($guardian->user->email);
        $this->assertSame('08031234567', $guardian->user->userProfile->phone);
        $this->assertSame('mother', $guardian->relationship);
    }

    /** A second child with the same mother's number joins her account. */
    public function test_a_sibling_attaches_to_the_same_parent_by_phone(): void
    {
        $this->enrol()->assertCreated();

        // Same number, written differently.
        $this->enrol([
            'name' => 'Chidi Okafor', 'gender' => 'male',
            'guardians' => [['name' => 'Mother of Chidi', 'phone' => '+234 803 123 4567']],
        ])->assertCreated();

        $this->assertSame(1, Guardian::withoutGlobalScopes()->where('school_id', $this->school->id)->count());
        $this->assertSame(2, DB::table('student_guardian')->count());
    }

    public function test_an_arm_from_another_class_is_refused(): void
    {
        $this->enrol(['class_id' => $this->jss2->id, 'arm_id' => $this->gold->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.arm_id.0', 'That arm does not belong to the chosen class.');
    }

    public function test_a_class_from_another_school_is_refused(): void
    {
        $theirs = SchoolClass::create(['school_id' => $this->rival->id, 'name' => 'JSS 1']);

        $this->enrol(['class_id' => $theirs->id, 'arm_id' => null])->assertStatus(422);
    }

    public function test_admission_numbers_are_unique_within_the_school(): void
    {
        $this->enrol(['admission_number' => 'GF/001'])->assertCreated();
        $this->enrol(['name' => 'Another', 'admission_number' => 'GF/001', 'guardians' => []])
            ->assertStatus(422);
    }

    /**
     * An email that belongs to someone who is not this school's parent must not
     * link the child to that account.
     */
    public function test_a_parent_email_owned_by_another_account_is_refused(): void
    {
        $this->person('Mr Teacher', 'taken@greenfield.test', 'teacher');

        $this->enrol(['guardians' => [['name' => 'Mrs O', 'phone' => '08039998888', 'email' => 'taken@greenfield.test']]])
            ->assertStatus(422);

        $this->assertSame(0, Student::withoutGlobalScopes()->count());
    }

    public function test_an_email_given_later_is_added_to_the_existing_parent(): void
    {
        $this->enrol()->assertCreated();

        $this->enrol([
            'name' => 'Chidi Okafor', 'gender' => 'male',
            'guardians' => [['name' => 'Mrs Okafor', 'phone' => '08031234567', 'email' => 'mrs.okafor@mail.test']],
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['name' => 'Mrs Okafor', 'email' => 'mrs.okafor@mail.test']);
    }

    // ------------------------------------------------------------- importing

    /** The template, uploaded unchanged, imports. Headers cannot drift from the importer. */
    public function test_the_student_template_round_trips_through_the_importer(): void
    {
        $csv = $this->actingAs($this->admin, 'sanctum')
            ->get('/api/v1/students/import/template')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('guardian_phone', $csv);

        $file = UploadedFile::fake()->createWithContent('students.csv', $csv);

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/students/import/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('summary.ready', 1)
            ->assertJsonPath('summary.errors', 0);
    }

    /** No more `guardian-0803…@school.local` for people with no email. */
    public function test_import_invents_no_email_addresses(): void
    {
        $csv = "name,gender,class,guardian_name,guardian_phone\n"
            ."Bola Ade,female,JSS 1,Mr Ade,08035551111\n";

        $preview = $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/students/import/preview', ['file' => UploadedFile::fake()->createWithContent('s.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/students/import/commit', ['token' => $preview->json('token')])
            ->assertOk();

        $this->assertSame(0, User::where('email', 'like', '%.local')->count());
        $this->assertDatabaseHas('users', ['name' => 'Bola Ade', 'email' => null]);
        $this->assertDatabaseHas('users', ['name' => 'Mr Ade', 'email' => null]);
    }

    /** The migration clears addresses the old importer invented. */
    public function test_invented_local_addresses_are_cleared(): void
    {
        $invented = User::create(['name' => 'Old import', 'email' => 'guardian-0803@greenfield.local', 'password' => 'x']);
        $real = User::create(['name' => 'Real', 'email' => 'real@mail.test', 'password' => 'x']);

        (require database_path('migrations/2026_09_26_050000_make_user_email_optional.php'))->up();

        $this->assertNull($invented->fresh()->email);
        $this->assertSame('real@mail.test', $real->fresh()->email);
    }

    // ---------------------------------------------------------------- lookup

    public function test_the_student_lookup_returns_only_what_a_picker_needs(): void
    {
        $this->enrol()->assertCreated();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/students/lookup?search=okaf')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ada Okafor')
            ->assertJsonPath('data.0.class', 'JSS 1 Gold');

        $this->assertSame(['id', 'name', 'admission_number', 'class'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString('2013-05-02', $response->getContent());
    }

    public function test_the_lookup_needs_two_characters_and_stays_in_the_school(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/students/lookup?search=a')->assertStatus(422);

        $rivalAdmin = $this->person('Rival Admin', 'admin@rival.test', 'school_admin', $this->rival);
        $this->enrol()->assertCreated();

        $this->actingAs($rivalAdmin, 'sanctum')
            ->getJson('/api/v1/students/lookup?search=okafor')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ----------------------------------------------------------------- staff

    public function test_a_teacher_is_added_with_a_staff_record(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/staff', [
            'name' => 'Mr Bello', 'email' => 'bello@greenfield.test', 'phone' => '0802 000 1111',
            'role' => 'teacher', 'staff_id' => 'STF/001', 'designation' => 'Maths teacher',
        ])->assertCreated();

        $user = User::where('email', 'bello@greenfield.test')->firstOrFail();

        $this->assertSame('teacher', $user->userProfile->role);
        $this->assertDatabaseHas('staff', ['user_id' => $user->id, 'staff_id' => 'STF/001', 'school_id' => $this->school->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/staff/directory')
            ->assertOk()
            ->assertJsonFragment(['email' => 'bello@greenfield.test', 'role' => 'teacher', 'staff_id' => 'STF/001']);
    }

    /** A preview creates nothing; a file with any bad row creates nothing either. */
    public function test_staff_import_previews_and_refuses_a_file_with_errors(): void
    {
        $csv = "Name,Email,Role,Staff ID\n"
            ."Mrs Ade,ade@greenfield.test,teacher,STF/010\n"
            ."Nurse Joy,joy@greenfield.test,nurse,STF/011\n"
            ."Bad Row,not-an-email,principal,STF/010\n";

        $dry = $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/staff/import', ['file' => UploadedFile::fake()->createWithContent('staff.csv', $csv), 'dry_run' => 1], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('summary.ready', 2)
            ->assertJsonPath('summary.errors', 1);

        $bad = collect($dry->json('rows'))->firstWhere('line', 4);
        $this->assertNotEmpty($bad['errors']);
        $this->assertStringContainsString('repeats line 2', implode(' ', $bad['errors']));

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/staff/import', ['file' => UploadedFile::fake()->createWithContent('staff.csv', $csv)], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, Staff::withoutGlobalScopes()->count());
    }

    public function test_the_staff_template_round_trips_and_imports(): void
    {
        $csv = $this->actingAs($this->admin, 'sanctum')->get('/api/v1/staff/import/template')->assertOk()->getContent();

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/staff/import', ['file' => UploadedFile::fake()->createWithContent('staff.csv', $csv)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('created', 1);
    }

    // ----------------------------------------------------------------- nurse

    public function test_a_nurse_records_a_visit_as_herself(): void
    {
        $nurse = $this->person('Nurse Joy', 'joy@greenfield.test', 'nurse');
        $student = Student::withoutGlobalScopes()->findOrFail($this->enrol()->json('student.id'));

        $this->actingAs($nurse, 'sanctum')->postJson('/api/v1/health/clinic-visit', [
            'student_id' => $student->id, 'symptoms' => 'Headache', 'treatment' => 'Rest',
        ])->assertCreated();

        $this->assertDatabaseHas('clinic_visits', ['student_id' => $student->id, 'attending_nurse' => 'Nurse Joy']);

        $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/health/clinic-visits')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/students/lookup?search=ada')->assertOk();
    }

    /** The clinic and the lookup, and nothing else. */
    public function test_a_nurse_cannot_reach_the_rest_of_the_school(): void
    {
        $nurse = $this->person('Nurse Joy', 'joy@greenfield.test', 'nurse');

        foreach (['/api/v1/students', '/api/v1/analytics/principal', '/api/v1/staff/directory', '/api/v1/finance/defaulters', '/api/v1/notifications/history'] as $url) {
            $this->actingAs($nurse, 'sanctum')->getJson($url)->assertForbidden();
        }
    }

    public function test_a_teacher_still_names_the_nurse(): void
    {
        $teacher = $this->person('Mr Chidi', 'chidi@greenfield.test', 'teacher');
        $student = Student::withoutGlobalScopes()->findOrFail($this->enrol()->json('student.id'));

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/health/clinic-visit', [
            'student_id' => $student->id, 'symptoms' => 'Cough', 'treatment' => 'Water',
        ])->assertStatus(422)->assertJsonValidationErrors('attending_nurse');
    }
}
