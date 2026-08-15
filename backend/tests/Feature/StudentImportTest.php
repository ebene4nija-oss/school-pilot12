<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://pilotacademy.localhost/api/v1';

    private function seedSchool(): array
    {
        $school = School::create(['name' => 'Pilot Academy', 'slug' => 'pilot-academy', 'subdomain' => 'pilotacademy']);

        $admin = User::factory()->create();
        UserProfile::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin']);

        $session = AcademicSession::create([
            'school_id' => $school->id,
            'name' => '2025/2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-07-31',
            'is_current' => true,
        ]);

        $jss1 = SchoolClass::create(['school_id' => $school->id, 'name' => 'JSS 1', 'level_category' => 'junior_secondary', 'order_index' => 1]);
        $gold = Arm::create(['school_id' => $school->id, 'class_id' => $jss1->id, 'name' => 'Gold']);

        return [
            'school' => $school,
            'admin' => $admin,
            'session' => $session,
            'jss1' => $jss1,
            'gold' => $gold,
            'token' => $admin->createToken('t')->plainTextToken,
        ];
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('register.csv', $content);
    }

    private function asAdmin(array $s)
    {
        return $this->withHeader('Authorization', 'Bearer ' . $s['token']);
    }

    private function preview(array $s, string $csv, array $extra = [])
    {
        return $this->asAdmin($s)->postJson(self::HOST . '/students/import/preview', array_merge([
            'file' => $this->csv($csv),
        ], $extra));
    }

    // ─── Preview ────────────────────────────────────────────────────

    public function test_preview_resolves_class_and_arm_by_name_in_any_column_order()
    {
        $s = $this->seedSchool();

        $response = $this->preview($s, "Surname,First name,Arm,Class,Sex\n"
            . "Okafor,Emeka,Gold,JSS 1,M\n"
            . "Bello,Fatima,Gold,JSS 1,F\n");

        $response->assertStatus(200)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.ready', 2)
            ->assertJsonPath('summary.errors', 0);

        $rows = $response->json('rows');

        $this->assertEquals('Emeka Okafor', $rows[0]['name']);
        $this->assertEquals($s['jss1']->id, $rows[0]['class_id']);
        $this->assertEquals($s['gold']->id, $rows[0]['arm_id']);
        $this->assertEquals('male', $rows[0]['gender']);
        $this->assertEquals('female', $rows[1]['gender']);
    }

    public function test_preview_creates_nothing()
    {
        $s = $this->seedSchool();

        $this->preview($s, "Name,Class\nEmeka Okafor,JSS 1\n")->assertStatus(200);

        $this->assertEquals(0, Student::withoutGlobalScopes()->count());
    }

    /**
     * The whole point of the class-targeted mode: a sheet straight off the
     * register, with no class column, imported into a chosen class.
     */
    public function test_a_class_can_be_chosen_for_a_whole_file_with_no_class_column()
    {
        $s = $this->seedSchool();

        $response = $this->preview($s, "Name\nEmeka Okafor\nFatima Bello\n", [
            'class_id' => $s['jss1']->id,
            'arm_id' => $s['gold']->id,
        ]);

        $response->assertStatus(200)->assertJsonPath('summary.ready', 2);

        foreach ($response->json('rows') as $row) {
            $this->assertEquals($s['jss1']->id, $row['class_id']);
            $this->assertEquals($s['gold']->id, $row['arm_id']);
        }
    }

    public function test_rows_with_no_class_at_all_are_errors_not_silent_nulls()
    {
        $s = $this->seedSchool();

        $response = $this->preview($s, "Name\nEmeka Okafor\n");

        $response->assertStatus(200)->assertJsonPath('summary.errors', 1);
        $this->assertStringContainsString('No class', $response->json('rows.0.errors.0'));
    }

    public function test_an_unknown_class_name_is_named_in_the_error()
    {
        $s = $this->seedSchool();

        $response = $this->preview($s, "Name,Class\nEmeka Okafor,JSS 9\n");

        $response->assertStatus(200)->assertJsonPath('summary.errors', 1);
        $this->assertStringContainsString('JSS 9', $response->json('rows.0.errors.0'));
    }

    public function test_duplicates_within_the_file_are_caught()
    {
        $s = $this->seedSchool();

        $response = $this->preview($s, "Name,Class,Admission No\n"
            . "Emeka Okafor,JSS 1,ADM/001\n"
            . "Emeka Okafor,JSS 1,ADM/001\n");

        $response->assertStatus(200)
            ->assertJsonPath('summary.ready', 1)
            ->assertJsonPath('summary.errors', 1);

        $this->assertStringContainsString('more than once', $response->json('rows.1.errors.0'));
    }

    public function test_an_admission_number_already_in_use_is_caught()
    {
        $s = $this->seedSchool();

        $existing = User::factory()->create();
        UserProfile::create(['school_id' => $s['school']->id, 'user_id' => $existing->id, 'role' => 'student']);
        Student::create([
            'school_id' => $s['school']->id,
            'user_id' => $existing->id,
            'admission_number' => 'ADM/001',
            'gender' => 'male',
            'status' => 'active',
        ]);

        $response = $this->preview($s, "Name,Class,Admission No\nEmeka Okafor,JSS 1,ADM/001\n");

        $response->assertStatus(200)->assertJsonPath('summary.errors', 1);
        $this->assertStringContainsString('already belongs', $response->json('rows.0.errors.0'));
    }

    public function test_an_xlsx_is_refused_with_an_instruction_rather_than_parsed_as_rubbish()
    {
        $s = $this->seedSchool();

        $file = UploadedFile::fake()->createWithContent('register.xlsx', "PK\x03\x04binary rubbish");

        $this->asAdmin($s)->postJson(self::HOST . '/students/import/preview', ['file' => $file])
            ->assertStatus(422);
    }

    public function test_dates_are_read_day_first()
    {
        $s = $this->seedSchool();

        $response = $this->preview($s, "Name,Class,DOB\nEmeka Okafor,JSS 1,03/04/2015\n");

        $response->assertStatus(200);
        $this->assertEquals('2015-04-03', $response->json('rows.0.date_of_birth'));
    }

    public function test_unrecognised_columns_are_reported_back()
    {
        $s = $this->seedSchool();

        $response = $this->preview($s, "Name,Class,Favourite Colour\nEmeka Okafor,JSS 1,Blue\n");

        $response->assertStatus(200);
        $this->assertContains('Favourite Colour', $response->json('unrecognised_columns'));
    }

    // ─── Commit ─────────────────────────────────────────────────────

    public function test_commit_creates_students_placed_and_enrolled()
    {
        $s = $this->seedSchool();

        $preview = $this->preview($s, "Name,Class,Arm\nEmeka Okafor,JSS 1,Gold\n");
        $preview->assertStatus(200);

        $response = $this->asAdmin($s)->postJson(self::HOST . '/students/import/commit', [
            'token' => $preview->json('token'),
        ]);

        $response->assertStatus(200)->assertJsonPath('created', 1);

        $student = Student::withoutGlobalScopes()->first();

        $this->assertEquals($s['jss1']->id, $student->class_id);
        $this->assertEquals($s['gold']->id, $student->arm_id);
        $this->assertNotNull($student->admission_number);

        // Enrolled in the running session, or the next rollover skips the
        // entire intake.
        $this->assertDatabaseHas('student_enrollments', [
            'student_id' => $student->id,
            'session_id' => $s['session']->id,
            'class_id' => $s['jss1']->id,
            'status' => 'active',
        ]);
    }

    public function test_a_pupil_with_no_email_still_gets_an_account()
    {
        $s = $this->seedSchool();

        $preview = $this->preview($s, "Name,Class,Admission No\nEmeka Okafor,JSS 1,ADM/001\n");

        $this->asAdmin($s)->postJson(self::HOST . '/students/import/commit', [
            'token' => $preview->json('token'),
        ])->assertStatus(200)->assertJsonPath('created', 1);

        $student = Student::withoutGlobalScopes()->with('user')->first();

        $this->assertEquals('ADM/001', $student->admission_number);
        $this->assertStringEndsWith('@pilotacademy.local', $student->user->email);
    }

    public function test_only_valid_rows_are_created_and_bad_rows_never_reach_the_database()
    {
        $s = $this->seedSchool();

        $preview = $this->preview($s, "Name,Class\n"
            . "Emeka Okafor,JSS 1\n"
            . "Nobody,JSS 9\n"
            . "Fatima Bello,JSS 1\n");

        $preview->assertStatus(200)->assertJsonPath('summary.errors', 1);

        $this->asAdmin($s)->postJson(self::HOST . '/students/import/commit', [
            'token' => $preview->json('token'),
        ])->assertStatus(200)->assertJsonPath('created', 2);

        $this->assertEquals(2, Student::withoutGlobalScopes()->count());
        $this->assertDatabaseMissing('users', ['name' => 'Nobody']);
    }

    public function test_siblings_sharing_a_phone_number_get_one_parent_account()
    {
        $s = $this->seedSchool();

        $preview = $this->preview($s, "Name,Class,Guardian Name,Guardian Phone\n"
            . "Emeka Okafor,JSS 1,Mr Okafor,08031234567\n"
            . "Ada Okafor,JSS 1,Mr Okafor,0803 123 4567\n");

        $this->asAdmin($s)->postJson(self::HOST . '/students/import/commit', [
            'token' => $preview->json('token'),
        ])->assertStatus(200)->assertJsonPath('created', 2);

        $this->assertEquals(1, Guardian::withoutGlobalScopes()->count());

        $guardian = Guardian::withoutGlobalScopes()->first();
        $this->assertEquals(2, $guardian->students()->count());
    }

    public function test_a_token_can_only_be_redeemed_once()
    {
        $s = $this->seedSchool();

        $preview = $this->preview($s, "Name,Class\nEmeka Okafor,JSS 1\n");
        $token = $preview->json('token');

        $this->asAdmin($s)->postJson(self::HOST . '/students/import/commit', ['token' => $token])
            ->assertStatus(200);

        $this->asAdmin($s)->postJson(self::HOST . '/students/import/commit', ['token' => $token])
            ->assertStatus(410);

        $this->assertEquals(1, Student::withoutGlobalScopes()->count());
    }

    public function test_another_schools_token_is_not_redeemable()
    {
        $s = $this->seedSchool();
        $preview = $this->preview($s, "Name,Class\nEmeka Okafor,JSS 1\n");

        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);
        $otherAdmin = User::factory()->create();
        UserProfile::create(['school_id' => $other->id, 'user_id' => $otherAdmin->id, 'role' => 'school_admin']);

        // The guard caches the user it resolved for the preview request; without
        // this the second call would still be acting as Pilot Academy's admin
        // and the test would pass for the wrong reason.
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer ' . $otherAdmin->createToken('t')->plainTextToken)
            ->postJson('http://other.localhost/api/v1/students/import/commit', [
                'token' => $preview->json('token'),
            ])
            ->assertStatus(410);

        $this->assertEquals(0, Student::withoutGlobalScopes()->count());
    }

    public function test_an_arm_from_another_class_is_rejected_at_upload()
    {
        $s = $this->seedSchool();

        $otherClass = SchoolClass::create(['school_id' => $s['school']->id, 'name' => 'JSS 2', 'order_index' => 2]);

        $this->asAdmin($s)->postJson(self::HOST . '/students/import/preview', [
            'file' => $this->csv("Name\nEmeka Okafor\n"),
            'class_id' => $otherClass->id,
            'arm_id' => $s['gold']->id,
        ])->assertStatus(422)->assertJsonValidationErrors('arm_id');
    }

    // ─── Legacy endpoint ────────────────────────────────────────────

    /**
     * The published contract for installed mobile clients: a three-column
     * file, a `successful_count`, and a list of row errors.
     */
    public function test_legacy_import_keeps_its_response_shape()
    {
        $s = $this->seedSchool();

        $existing = User::create([
            'name' => 'Existing',
            'email' => 'existing@pilotacademy.edu.ng',
            'password' => 'x',
        ]);
        UserProfile::create(['school_id' => $s['school']->id, 'user_id' => $existing->id, 'role' => 'student']);

        $response = $this->asAdmin($s)->postJson(self::HOST . '/students/import', [
            'file' => $this->csv("Name,Email,Gender\n"
                . "Fatima Bello,fatima@pilotacademy.edu.ng,female\n"
                . "Bad Row,existing@pilotacademy.edu.ng,male\n"
                . "Kelechi Iheanacho,kelechi@pilotacademy.edu.ng,male\n"),
        ]);

        $response->assertStatus(200)->assertJson(['successful_count' => 2]);

        $this->assertCount(1, $response->json('errors'));
        $this->assertDatabaseHas('users', ['email' => 'fatima@pilotacademy.edu.ng']);
        $this->assertDatabaseHas('users', ['email' => 'kelechi@pilotacademy.edu.ng']);
        $this->assertDatabaseMissing('users', ['name' => 'Bad Row']);
    }
}
