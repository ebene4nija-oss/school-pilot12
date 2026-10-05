<?php

namespace Tests\Feature;

use App\Models\AdmissionApplication;
use App\Models\AdmissionSetting;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each school chooses how parents reach it about admissions: any mix of the
 * online form, WhatsApp, email and phone. Its public page shows only what it
 * chose, and the online form takes applications only while admissions are
 * open and the form is on.
 */
class AdmissionContactMethodsTest extends TestCase
{
    use RefreshDatabase;

    private string $host = 'http://greenfield.schoolpilot.test/api/v1';

    private School $school;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Greenfield International School', 'slug' => 'greenfield', 'subdomain' => 'greenfield',
            'email' => 'info@greenfield.test', 'phone' => '08031234567', 'status' => 'active',
        ]);
        // Publishing is deliberate (not mass-assignable): set it directly.
        $this->school->forceFill(['public_profile_published' => true])->save();
        $this->admin = User::create(['name' => 'Head', 'email' => 'admin@gf.test', 'password' => bcrypt('password')]);
        $this->admin->userProfile()->create(['school_id' => $this->school->id, 'role' => 'school_admin']);
    }

    private function save(array $settings)
    {
        return $this->actingAs($this->admin, 'sanctum')->putJson("{$this->host}/admissions/settings", $settings);
    }

    private function application(): array
    {
        return ['section' => 'primary', 'applicant_name' => 'Amaka Eze', 'date_of_birth' => '2019-02-01', 'gender' => 'female', 'guardians' => [['name' => 'Mr Eze', 'phone' => '08033334444']]];
    }

    public function test_a_school_opens_admissions_with_whatsapp_and_phone_only(): void
    {
        $this->save([
            'admissions_status' => 'open',
            'admission_session' => '2026/2027',
            'instructions' => 'Entrance assessment holds every Saturday in July.',
            'whatsapp_enabled' => true,
            'whatsapp_number' => '0803 123 4567',
            'whatsapp_message' => 'Hello, I would like to ask about admission.',
            'phone_enabled' => true,
            'admissions_phone' => '0809 765 4321',
        ])->assertOk()
            ->assertJsonPath('whatsapp_number', '+2348031234567')
            ->assertJsonPath('admissions_phone', '+2348097654321')
            ->assertJsonPath('school_contacts.email', 'info@greenfield.test');

        $this->getJson("{$this->host}/public/school-profile")
            ->assertOk()
            ->assertJsonPath('admissions_open', true)
            ->assertJsonPath('admissions.status', 'open')
            ->assertJsonPath('admissions.session', '2026/2027')
            ->assertJsonPath('admissions.methods.online', false)
            ->assertJsonPath('admissions.methods.whatsapp.number', '2348031234567')
            ->assertJsonPath('admissions.methods.whatsapp.message', 'Hello, I would like to ask about admission.')
            ->assertJsonPath('admissions.methods.email', null)
            ->assertJsonPath('admissions.methods.phone', '+2348097654321');

        // No online form chosen: nothing can be submitted through it.
        $this->getJson("{$this->host}/public/admissions/form")->assertNotFound();
        $this->postJson("{$this->host}/public/admissions/apply", $this->application())->assertNotFound();
    }

    public function test_the_online_form_takes_applications_only_while_admissions_are_open(): void
    {
        $this->save(['admissions_status' => 'open', 'admission_session' => '2026/2027', 'public_form_enabled' => true, 'open_sections' => ['primary']])->assertOk();

        $this->getJson("{$this->host}/public/admissions/form")->assertOk()->assertJsonPath('session', '2026/2027');
        $this->postJson("{$this->host}/public/admissions/apply", $this->application())->assertCreated();
        $this->getJson("{$this->host}/public/school-profile")->assertJsonPath('admissions.methods.online', true);

        $this->save(['admissions_status' => 'closed', 'closed_message' => 'Admissions for 2027/2028 open in May.'])->assertOk();

        $this->postJson("{$this->host}/public/admissions/apply", $this->application())->assertNotFound();
        $this->assertSame(1, AdmissionApplication::count());

        $this->getJson("{$this->host}/public/school-profile")
            ->assertJsonPath('admissions_open', false)
            ->assertJsonPath('admissions.status', 'closed')
            ->assertJsonPath('admissions.closed_message', 'Admissions for 2027/2028 open in May.')
            ->assertJsonMissingPath('admissions.methods');
    }

    public function test_admissions_cannot_open_with_no_way_to_reach_the_school(): void
    {
        $this->save(['admissions_status' => 'open'])
            ->assertStatus(422)->assertJsonValidationErrors('admissions_status');

        $this->assertNull(AdmissionSetting::where('school_id', $this->school->id)->first());
    }

    public function test_every_method_turned_on_needs_its_contact(): void
    {
        $this->save(['admissions_status' => 'open', 'whatsapp_enabled' => true, 'email_enabled' => true, 'phone_enabled' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_number', 'admissions_email', 'admissions_phone']);

        // Turned on in one save, contact added in the next: checked on the result.
        $this->save(['email_enabled' => true, 'admissions_email' => 'admissions@greenfield.test'])->assertOk();
        $this->save(['admissions_email' => null])->assertStatus(422)->assertJsonValidationErrors('admissions_email');
    }

    public function test_contacts_and_session_are_validated(): void
    {
        $this->save(['whatsapp_number' => '12345'])->assertStatus(422)->assertJsonValidationErrors('whatsapp_number');
        $this->save(['admissions_phone' => '+44 20 7946 0000'])->assertStatus(422)->assertJsonValidationErrors('admissions_phone');
        $this->save(['admission_session' => '2026-27'])->assertStatus(422)->assertJsonValidationErrors('admission_session');
        $this->save(['admissions_email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('admissions_email');
    }

    public function test_a_school_that_never_set_admissions_shows_nothing_about_them(): void
    {
        $this->getJson("{$this->host}/public/school-profile")
            ->assertOk()
            ->assertJsonPath('admissions_open', false)
            ->assertJsonPath('admissions', null);
    }

    public function test_an_unpublished_school_shows_no_admissions_even_when_open(): void
    {
        $this->save(['admissions_status' => 'open', 'phone_enabled' => true, 'admissions_phone' => '08031234567'])->assertOk();
        $this->school->forceFill(['public_profile_published' => false])->save();

        $this->getJson("{$this->host}/public/school-profile")->assertJsonPath('admissions', null);
    }

    public function test_a_teacher_cannot_change_admission_settings(): void
    {
        $teacher = User::create(['name' => 'Mr Bello', 'email' => 'bello@gf.test', 'password' => bcrypt('password')]);
        $teacher->userProfile()->create(['school_id' => $this->school->id, 'role' => 'teacher']);

        $this->actingAs($teacher, 'sanctum')->putJson("{$this->host}/admissions/settings", ['admissions_status' => 'closed'])->assertForbidden();
    }
}
