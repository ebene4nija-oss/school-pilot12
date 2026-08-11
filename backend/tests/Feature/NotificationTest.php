<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\NotificationLog;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * §7.13. NotificationController had no routes at all before this, SmsService
 * was never called from anywhere, and push did not exist.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Notify Academy',
            'slug' => 'notify',
            'subdomain' => 'notify',
            'domain' => 'notify.schoolpilot.test',
        ]);

        $this->admin = $this->user('Admin', 'admin@notify.test', 'school_admin', null);
        $this->parent = $this->user('Parent', 'parent@notify.test', 'parent', '08030000000');
    }

    private function user(string $name, string $email, string $role, ?string $phone): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password123')]);
        $user->userProfile()->create([
            'school_id' => $this->school->id,
            'role' => $role,
            'phone' => $phone,
        ]);

        return $user;
    }

    private function url(string $path): string
    {
        return 'http://notify.schoolpilot.test/api/v1' . $path;
    }

    public function test_device_can_be_registered_for_push()
    {
        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/notifications/devices'), [
                'token' => 'fcm-token-abc',
                'platform' => 'android',
                'device_name' => 'Tecno Spark',
            ])
            ->assertStatus(201);

        $this->assertDatabaseCount('device_tokens', 1);
    }

    /** A reinstall must not leave the user receiving every push twice. */
    public function test_registering_the_same_token_twice_does_not_duplicate()
    {
        foreach (range(1, 2) as $_) {
            $this->actingAs($this->parent, 'sanctum')
                ->postJson($this->url('/notifications/devices'), [
                    'token' => 'fcm-token-abc',
                    'platform' => 'android',
                ]);
        }

        $this->assertDatabaseCount('device_tokens', 1);
    }

    public function test_device_can_be_unregistered_on_logout()
    {
        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/notifications/devices'), ['token' => 'tok', 'platform' => 'ios']);

        $this->actingAs($this->parent, 'sanctum')
            ->deleteJson($this->url('/notifications/devices'), ['token' => 'tok'])
            ->assertStatus(200);

        $this->assertDatabaseCount('device_tokens', 0);
    }

    /** The raw FCM token is a send credential and must not come back out. */
    public function test_raw_token_is_never_returned_to_a_client()
    {
        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/notifications/devices'), ['token' => 'secret-token', 'platform' => 'android']);

        $this->assertArrayNotHasKey('token', DeviceToken::first()->toArray());
    }

    public function test_sms_broadcast_is_sent_and_logged()
    {
        Http::fake(['api.ng.termii.com/*' => Http::response(['message_id' => 'abc'], 200)]);
        config()->set('services.sms.api_key', 'live-key');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/notifications/broadcast'), [
                'user_ids' => [$this->parent->id],
                'body' => 'Second term fees are due on Friday.',
                'channels' => ['sms'],
                'category' => 'fee_reminder',
            ])
            ->assertStatus(202)
            ->assertJsonPath('recipients', 1)
            ->assertJsonStructure(['batch_id', 'status_url']);

        // Queued, so the send happens in the worker. The suite runs the sync
        // queue driver, so the log is written by the time this returns.
        $log = NotificationLog::first();
        $this->assertSame('sms', $log->channel);
        $this->assertSame('sent', $log->status);
        $this->assertSame('fee_reminder', $log->category);
        $this->assertSame($this->admin->id, $log->sent_by);
    }

    /**
     * With push unconfigured the attempt is recorded as failed rather than
     * reported as delivered.
     */
    public function test_push_without_configuration_is_recorded_as_failed_not_sent()
    {
        config()->set('services.fcm.credentials', null);

        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/notifications/devices'), ['token' => 'tok', 'platform' => 'android']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/notifications/broadcast'), [
                'user_ids' => [$this->parent->id],
                'body' => 'Results are published.',
                'channels' => ['push'],
            ])
            ->assertStatus(202);

        $this->assertSame('failed', NotificationLog::first()->status);
    }

    /**
     * The regression this whole change exists for.
     *
     * With no SMS key the old SmsService returned
     * `['status' => 'success', 'message_id' => 'mock_msg_…']`, so the school's
     * notification history filled up with fee reminders that were never sent.
     * An unconfigured channel must read as failed.
     */
    public function test_sms_without_configuration_is_recorded_as_failed_not_sent()
    {
        Http::fake();
        config()->set('services.sms.api_key', null);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/notifications/broadcast'), [
                'user_ids' => [$this->parent->id],
                'body' => 'Second term fees are due on Friday.',
                'channels' => ['sms'],
            ])
            ->assertStatus(202);

        $log = NotificationLog::first();
        $this->assertSame('failed', $log->status);

        // And nothing was attempted against the gateway.
        Http::assertNothingSent();
    }

    public function test_whatsapp_without_configuration_is_recorded_as_failed_not_sent()
    {
        Http::fake();
        config()->set('services.whatsapp.api_key', null);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/notifications/broadcast'), [
                'user_ids' => [$this->parent->id],
                'body' => 'Results are ready.',
                'channels' => ['whatsapp'],
            ])
            ->assertStatus(202);

        $this->assertSame('failed', NotificationLog::first()->status);
        Http::assertNothingSent();
    }

    /** A gateway that answers 4xx is a failure, however configured we are. */
    public function test_sms_rejected_by_the_gateway_is_recorded_as_failed()
    {
        Http::fake(['api.ng.termii.com/*' => Http::response(['error' => 'insufficient balance'], 402)]);
        config()->set('services.sms.api_key', 'live-key');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/notifications/broadcast'), [
                'user_ids' => [$this->parent->id],
                'body' => 'Fees are due.',
                'channels' => ['sms'],
            ])
            ->assertStatus(202);

        $this->assertSame('failed', NotificationLog::first()->status);
    }

    /**
     * Termii answers 200 with a status field on some rejections rather than an
     * HTTP error code. `!== 'error'` used to treat that as delivered.
     */
    public function test_whatsapp_soft_failure_at_200_is_recorded_as_failed()
    {
        Http::fake(['api.ng.termii.com/*' => Http::response(['status' => 'failed'], 200)]);
        config()->set('services.whatsapp.api_key', 'live-key');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/notifications/broadcast'), [
                'user_ids' => [$this->parent->id],
                'body' => 'Attendance update.',
                'channels' => ['whatsapp'],
            ])
            ->assertStatus(202);

        $this->assertSame('failed', NotificationLog::first()->status);
    }

    public function test_recipients_outside_the_school_are_refused()
    {
        $otherSchool = School::create([
            'name' => 'Other', 'slug' => 'other', 'subdomain' => 'other',
            'domain' => 'other.schoolpilot.test',
        ]);

        $outsider = User::create([
            'name' => 'Outsider', 'email' => 'out@other.test', 'password' => bcrypt('password123'),
        ]);
        $outsider->userProfile()->create(['school_id' => $otherSchool->id, 'role' => 'parent']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/notifications/broadcast'), [
                'user_ids' => [$outsider->id],
                'body' => 'Cross-tenant message.',
                'channels' => ['sms'],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_history_reports_what_the_school_sent()
    {
        config()->set('services.fcm.credentials', null);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/notifications/broadcast'), [
                'user_ids' => [$this->parent->id],
                'body' => 'Anything.',
                'channels' => ['push'],
            ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/notifications/history'))
            ->assertStatus(200)
            ->assertJsonPath('totals.0.channel', 'push');
    }

    public function test_parent_cannot_broadcast()
    {
        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/notifications/broadcast'), [
                'user_ids' => [$this->admin->id],
                'body' => 'Spam.',
                'channels' => ['sms'],
            ])
            ->assertStatus(403);
    }
}
