<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\NotificationLog;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolSmsGateway;
use App\Models\Student;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Notifications that actually reach people.
 *
 * "Notifications are not working" was four problems stacked: the broadcast
 * form asked for raw user ids, it offered email and the API refused it, SMS was
 * one platform key when schools send from their own phones, and nothing said
 * which of these was the reason. These cover each.
 */
class SchoolMessagingTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAY = 'https://sms.example-gateway.test';

    private School $school;

    private School $rival;

    private User $admin;

    private User $teacher;

    private User $operator;

    private SchoolClass $jss1;

    private SchoolClass $jss2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.sms.api_key' => null]);
        PlatformSettings::forget();

        $this->school = School::create(['name' => 'Greenfield', 'slug' => 'greenfield', 'subdomain' => 'greenfield', 'status' => 'active']);
        $this->rival = School::create(['name' => 'Rival', 'slug' => 'rival', 'subdomain' => 'rival', 'status' => 'active']);

        $this->admin = $this->person('Head Admin', 'admin@greenfield.test', 'school_admin', $this->school);
        $this->teacher = $this->person('Mr Chidi', 'chidi@greenfield.test', 'teacher', $this->school);

        $this->operator = User::create(['name' => 'Ops', 'email' => 'ops@schoolpilot.test', 'password' => bcrypt('x')]);
        $this->operator->userProfile()->create(['school_id' => null, 'role' => 'super_admin']);

        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1', 'level' => 'JSS']);
        $this->jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2', 'level' => 'JSS']);
    }

    private function person(string $name, string $email, string $role, School $school, ?string $phone = null): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('x')]);
        $user->userProfile()->create(['school_id' => $school->id, 'role' => $role, 'phone' => $phone]);

        return $user;
    }

    /** A student in a class, with one guardian. Returns the guardian's user. */
    private function family(SchoolClass $class, string $slug, ?string $phone = '08031234567', ?string $email = null): User
    {
        $child = User::create(['name' => "Child {$slug}", 'email' => "child.{$slug}@greenfield.test", 'password' => bcrypt('x')]);
        $student = Student::create([
            'school_id' => $class->school_id, 'user_id' => $child->id, 'class_id' => $class->id,
            'admission_number' => strtoupper($slug), 'status' => 'active',
        ]);

        // users.email is NOT NULL, so every parent account has one today.
        $parent = User::create(['name' => "Parent {$slug}", 'email' => $email ?? "parent.{$slug}@parents.test", 'password' => bcrypt('x')]);
        $parent->userProfile()->create(['school_id' => $class->school_id, 'role' => 'parent', 'phone' => $phone]);

        $guardian = Guardian::create(['school_id' => $class->school_id, 'user_id' => $parent->id]);
        DB::table('student_guardian')->insert([
            'student_id' => $student->id, 'guardian_id' => $guardian->id, 'is_primary' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $parent;
    }

    private function saveGateway(array $overrides = []): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/sms-gateway', array_merge([
                'provider' => 'phone_gateway',
                'server_url' => self::GATEWAY,
                'api_key' => 'gw-key-abcdef123456',
                'device' => '1|0',
            ], $overrides))
            ->assertOk();
    }

    private function fakeGateway(bool $success = true, string $error = 'Device is offline.'): void
    {
        Http::fake([
            self::GATEWAY.'/services/send.php' => Http::response($success
                ? ['success' => true, 'data' => ['messages' => [['ID' => '42', 'status' => 'Pending']], 'credits' => '480']]
                : ['success' => false, 'data' => null, 'error' => ['code' => 500, 'message' => $error]]),
        ]);
    }

    // ------------------------------------------------------------ SMS account

    public function test_a_school_saves_its_own_gateway_and_the_key_is_never_returned(): void
    {
        $this->saveGateway();

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/school/sms-gateway')->assertOk();

        $response->assertJsonPath('data.provider', 'phone_gateway')
            ->assertJsonPath('data.key_hint', '••••3456')
            ->assertJsonPath('data.device', '1|0');
        $this->assertStringNotContainsString('gw-key-abcdef123456', $response->getContent());

        $raw = DB::table('school_sms_gateways')->value('api_key');
        $this->assertStringNotContainsString('gw-key', $raw);
    }

    /** The key travels in every request body, so the gateway must be HTTPS. */
    public function test_the_gateway_address_must_be_https(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/sms-gateway', [
                'provider' => 'phone_gateway',
                'server_url' => 'http://sms.example-gateway.test',
                'api_key' => 'gw-key-abcdef123456',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('server_url');
    }

    public function test_changing_the_phone_does_not_require_the_key_again(): void
    {
        $this->saveGateway();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/sms-gateway', [
                'provider' => 'phone_gateway',
                'server_url' => self::GATEWAY,
                'device' => '2',
            ])
            ->assertOk()
            ->assertJsonPath('data.device', '2')
            ->assertJsonPath('data.key_hint', '••••3456');
    }

    /** The request the gateway actually receives. */
    public function test_a_test_message_goes_to_the_gateway_in_its_own_format(): void
    {
        $this->saveGateway();
        $this->fakeGateway();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school/sms-gateway/test', ['phone' => '0803 123 4567'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.last_test_status', 'passed');

        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === self::GATEWAY.'/services/send.php'
                && $request->isForm()
                && $request['key'] === 'gw-key-abcdef123456'
                && $request['number'] === '+2348031234567'
                && $request['devices'] === '1|0'
                && $request['type'] === 'sms';
        });
    }

    /** A handset that is off must read as a failure, with the gateway's reason. */
    public function test_a_gateway_refusal_is_reported_not_swallowed(): void
    {
        $this->saveGateway();
        $this->fakeGateway(false, 'Device is offline.');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/school/sms-gateway/test', ['phone' => '08031234567'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Device is offline.')
            ->assertJsonPath('data.last_test_status', 'failed');
    }

    public function test_balance_reads_the_gateway_credits(): void
    {
        $this->saveGateway();
        $this->fakeGateway();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/school/sms-gateway/balance')
            ->assertOk()
            ->assertJsonPath('credits', '480');
    }

    public function test_a_teacher_cannot_see_or_change_the_schools_sms_account(): void
    {
        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/v1/school/sms-gateway')->assertForbidden();
        $this->actingAs($this->teacher, 'sanctum')
            ->putJson('/api/v1/school/sms-gateway', ['provider' => 'termii', 'api_key' => 'x'])
            ->assertForbidden();
    }

    // ------------------------------------------------------ sending over SMS

    /** Each school's messages go out through that school's own account. */
    public function test_a_parent_is_texted_through_their_schools_own_gateway(): void
    {
        $this->saveGateway();
        $this->fakeGateway();

        $parent = $this->family($this->jss1, 'a1', '08031234567');

        $result = app(NotificationService::class)->notify($parent, 'Fees are due Friday.', ['sms']);

        $this->assertSame('sent', $result['sms']['status'] ?? null);
        Http::assertSent(fn (HttpRequest $r) => $r['key'] === 'gw-key-abcdef123456' && $r['number'] === '+2348031234567');
    }

    /** A rival school's gateway must never carry this school's messages. */
    public function test_one_schools_gateway_never_sends_for_another(): void
    {
        SchoolSmsGateway::withoutGlobalScope('school_id')->create([
            'school_id' => $this->rival->id, 'provider' => 'phone_gateway',
            'server_url' => self::GATEWAY, 'api_key' => 'rival-key-999999', 'is_active' => true,
        ]);
        Http::fake();

        $parent = $this->family($this->jss1, 'a2');

        $result = app(NotificationService::class)->notify($parent, 'Hello', ['sms']);

        $this->assertSame('failed', $result['sms']['status'] ?? null);
        Http::assertNothingSent();
    }

    public function test_without_a_school_account_the_platform_key_is_the_fallback(): void
    {
        config(['services.sms.api_key' => 'platform-termii-key']);
        Http::fake(['api.ng.termii.com/*' => Http::response(['message_id' => 'T1'])]);

        $parent = $this->family($this->jss1, 'a3', '08031234567');

        $result = app(NotificationService::class)->notify($parent, 'Hello', ['sms']);

        $this->assertSame('sent', $result['sms']['status'] ?? null);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'termii') && $r['to'] === '2348031234567');
    }

    public function test_with_no_account_anywhere_sms_fails_honestly(): void
    {
        Http::fake();
        $parent = $this->family($this->jss1, 'a4');

        app(NotificationService::class)->notify($parent, 'Hello', ['sms']);

        $log = NotificationLog::withoutGlobalScope('school_id')->where('channel', 'sms')->latest('id')->first();
        $this->assertSame('failed', $log->status);
        $this->assertSame('SMS is not set up for this school.', $log->failure_reason);
        Http::assertNothingSent();
    }

    public function test_numbers_are_normalised_to_international_form(): void
    {
        $this->assertSame('+2348031234567', SmsService::normaliseNumber('08031234567'));
        $this->assertSame('+2348031234567', SmsService::normaliseNumber('0803 123 4567'));
        $this->assertSame('+2348031234567', SmsService::normaliseNumber('2348031234567'));
        $this->assertSame('+2348031234567', SmsService::normaliseNumber('+234 803 123 4567'));
        $this->assertSame('+2348031234567', SmsService::normaliseNumber('8031234567'));
    }

    // ------------------------------------------------------------- audiences

    /** "JSS 1 parents" means the guardians of JSS 1 students, and no one else. */
    public function test_a_class_audience_reaches_only_that_classs_parents(): void
    {
        $this->family($this->jss1, 'b1', '08030000001', 'b1@parents.test');
        $this->family($this->jss1, 'b2', null, 'b2@parents.test');
        $this->family($this->jss2, 'b3', '08030000003');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/notifications/audience-preview', ['audience' => ['type' => 'parents', 'class_id' => $this->jss1->id]])
            ->assertOk()
            ->assertJsonPath('recipients', 2)
            // One of the two has no phone on file.
            ->assertJsonPath('with_phone', 1)
            ->assertJsonPath('with_email', 2);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/notifications/audience-preview', ['audience' => ['type' => 'parents']])
            ->assertOk()
            ->assertJsonPath('recipients', 3);
    }

    public function test_a_broadcast_can_address_an_audience_and_use_email(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test']);

        $this->family($this->jss1, 'c1', null, 'c1@parents.test');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/notifications/broadcast', [
                'audience' => ['type' => 'parents', 'class_id' => $this->jss1->id],
                'body' => 'Open day is Saturday.',
                'channels' => ['email'],
            ])
            ->assertStatus(202)
            ->assertJsonPath('recipients', 1);

        $this->assertDatabaseHas('notification_logs', ['channel' => 'email', 'status' => 'sent', 'recipient' => 'c1@parents.test']);
    }

    public function test_an_audience_never_crosses_into_another_school(): void
    {
        $rivalClass = SchoolClass::create(['school_id' => $this->rival->id, 'name' => 'JSS 1', 'level' => 'JSS']);
        $this->family($rivalClass, 'r1');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/notifications/audience-preview', ['audience' => ['type' => 'parents']])
            ->assertOk()
            ->assertJsonPath('recipients', 0);
    }

    // ------------------------------------------------------ channel readiness

    public function test_channel_readiness_names_the_reason_for_each_gap(): void
    {
        config(['mail.default' => 'log']);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/notifications/channels')->assertOk();

        $response->assertJsonPath('sms.ready', false)
            ->assertJsonPath('email.ready', false)
            ->assertJsonPath('email.reason', 'Email is not set up on the platform yet. Contact SchoolPilot.');

        $this->saveGateway();

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/notifications/channels')
            ->assertJsonPath('sms.ready', true)
            ->assertJsonPath('sms.source', 'school');
    }

    // --------------------------------------------------------- email limits

    public function test_email_switched_off_by_the_platform_is_not_sent(): void
    {
        Mail::fake();
        $this->school->update(['email_enabled' => false]);
        $parent = $this->family($this->jss1, 'd1', null, 'd1@parents.test');

        $result = app(NotificationService::class)->notify($parent, 'Hello', ['email']);

        $this->assertSame('failed', $result['email']['status']);
        Mail::assertNothingSent();
    }

    public function test_the_daily_email_limit_stops_further_sends(): void
    {
        Mail::fake();
        $this->school->update(['email_daily_limit' => 1]);

        $first = $this->family($this->jss1, 'e1', null, 'e1@parents.test');
        $second = $this->family($this->jss1, 'e2', null, 'e2@parents.test');

        $this->assertSame('sent', app(NotificationService::class)->notify($first, 'One', ['email'])['email']['status']);
        $this->assertSame('failed', app(NotificationService::class)->notify($second, 'Two', ['email'])['email']['status']);

        $this->assertStringContainsString('Daily email limit of 1', NotificationLog::withoutGlobalScope('school_id')->latest('id')->value('failure_reason'));
    }

    /** A parent locked out of their account must still get the way back in. */
    public function test_password_links_are_sent_even_over_the_limit(): void
    {
        Mail::fake();
        $this->school->update(['email_daily_limit' => 1, 'email_enabled' => false]);
        $parent = $this->family($this->jss1, 'f1', null, 'f1@parents.test');

        $result = app(NotificationService::class)->notify($parent, 'Reset link', ['email'], ['category' => 'password_reset']);

        $this->assertSame('sent', $result['email']['status']);
    }

    // -------------------------------------------------- platform controls

    public function test_the_operator_sets_a_schools_email_limit_and_sees_todays_sends(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->school->id}/messaging", ['email_daily_limit' => 200, 'email_enabled' => true])
            ->assertOk()
            ->assertJsonPath('school.effective_email_limit', 200);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/platform/messaging')
            ->assertOk()
            ->assertJsonFragment(['subdomain' => 'greenfield', 'email_daily_limit' => 200]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.school.messaging_changed']);
    }

    /** A cap the school could raise itself would not be a cap. */
    public function test_a_school_admin_cannot_change_its_own_email_limit(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/platform/schools/{$this->school->id}/messaging", ['email_daily_limit' => 999999])
            ->assertForbidden();
    }
}
