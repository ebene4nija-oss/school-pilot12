<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\NotificationLog;
use App\Models\School;
use App\Models\User;
use App\Services\FcmService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Push over FCM HTTP v1 (§7.13, mobile gap G7).
 *
 * These tests exist because the previous implementation was not merely untested
 * but undeliverable: it posted to `https://fcm.googleapis.com/fcm/send`, an
 * endpoint Google decommissioned in June 2024. Nothing in the suite noticed,
 * because nothing asserted where the request went.
 *
 * So the first thing asserted here is the URL.
 */
class PushDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'schoolpilot-test';

    /** Generating a 2048-bit key per test is slower than the tests. */
    private static ?string $privateKey = null;

    private School $school;
    private User $admin;
    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Push Academy',
            'slug' => 'push',
            'subdomain' => 'push',
            'domain' => 'push.schoolpilot.test',
        ]);

        $this->admin = $this->user('Admin', 'admin@push.test', 'school_admin');
        $this->parent = $this->user('Parent', 'parent@push.test', 'parent');

        $this->configurePush();
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function url(string $path): string
    {
        return 'http://push.schoolpilot.test/api/v1' . $path;
    }

    /** A service account shaped exactly like the one Firebase hands you. */
    private function configurePush(): void
    {
        if (self::$privateKey === null) {
            /*
             * Generated rather than committed: a service-account private key
             * in the repo is exactly the thing secret scanners exist to catch,
             * and a reviewer should not have to establish that this one is a
             * fake. The explicit config file is for Windows dev boxes, where
             * PHP ships without an openssl.cnf and key generation fails
             * outright without one.
             */
            $config = tempnam(sys_get_temp_dir(), 'sp_openssl_') . '.cnf';
            file_put_contents($config, "[req]\ndistinguished_name=req\n");

            $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config];
            $resource = openssl_pkey_new($options);
            openssl_pkey_export($resource, $pem, null, $options);

            @unlink($config);
            self::$privateKey = $pem;
        }

        config()->set('services.fcm.credentials', json_encode([
            'type' => 'service_account',
            'project_id' => self::PROJECT,
            'client_email' => 'push@' . self::PROJECT . '.iam.gserviceaccount.com',
            'private_key' => self::$privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        // Left unset deliberately: it must fall back to the credentials.
        config()->set('services.fcm.project_id', null);
        config()->set('services.fcm.android_channel_id', 'schoolpilot_default');
    }

    private function fakeFirebase(?PromiseInterface $sendResponse = null): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'ya29.test-token',
                'expires_in' => 3599,
                'token_type' => 'Bearer',
            ]),
            'fcm.googleapis.com/*' => $sendResponse
                ?? Http::response(['name' => 'projects/' . self::PROJECT . '/messages/0:1234']),
        ]);
    }

    private function registerDevice(string $token, string $platform = 'android'): DeviceToken
    {
        return DeviceToken::create([
            'school_id' => $this->school->id,
            'user_id' => $this->parent->id,
            'token' => $token,
            'platform' => $platform,
        ]);
    }

    private function broadcastPush(string $body = 'Second term results are published.'): TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/notifications/broadcast'), [
                'user_ids' => [$this->parent->id],
                'body' => $body,
                'channels' => ['push'],
                'category' => 'result_published',
            ]);
    }

    /**
     * The regression that started all of this: push must address the v1
     * endpoint with an OAuth bearer token, not legacy /fcm/send with `key=`.
     */
    public function test_push_goes_to_the_http_v1_endpoint_with_a_bearer_token()
    {
        $this->fakeFirebase();
        $this->registerDevice('device-token-1');

        $this->broadcastPush()->assertStatus(202);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://fcm.googleapis.com/v1/projects/' . self::PROJECT . '/messages:send'
                && $request->hasHeader('Authorization', 'Bearer ya29.test-token');
        });

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fcm/send'));

        $this->assertSame('sent', NotificationLog::first()->status);
    }

    /** The v1 envelope the Flutter client is written against (§B10). */
    public function test_message_envelope_carries_the_channel_and_priority_the_client_expects()
    {
        $this->fakeFirebase();
        $this->registerDevice('device-token-1');

        $this->broadcastPush('Fees are due on Friday.');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'messages:send')) {
                return false;
            }

            $message = $request->data()['message'];

            return $message['token'] === 'device-token-1'
                && $message['notification']['body'] === 'Fees are due on Friday.'
                && $message['android']['priority'] === 'high'
                && $message['android']['notification']['channel_id'] === 'schoolpilot_default'
                && $message['apns']['payload']['aps']['content-available'] === 1;
        });
    }

    /**
     * v1 rejects a data map containing a non-string. Call sites pass integer
     * ids without thinking about it, so the service coerces rather than
     * letting one integer 400 the whole message.
     */
    public function test_data_payload_values_are_coerced_to_strings()
    {
        $this->fakeFirebase();
        $this->registerDevice('device-token-1');

        app(FcmService::class)->sendToToken(
            'device-token-1',
            'Results',
            'Ready.',
            ['invoice_id' => 42, 'unread' => true, 'route' => '/results', 'nothing' => null]
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'messages:send')) {
                return false;
            }

            $data = $request->data()['message']['data'];

            return $data === ['invoice_id' => '42', 'unread' => 'true', 'route' => '/results'];
        });
    }

    /** A handset that uninstalled must stop costing us a send on every broadcast. */
    public function test_unregistered_token_is_deleted()
    {
        $this->fakeFirebase(Http::response([
            'error' => [
                'code' => 404,
                'status' => 'NOT_FOUND',
                'message' => 'Requested entity was not found.',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                    'errorCode' => 'UNREGISTERED',
                ]],
            ],
        ], 404));

        $this->registerDevice('dead-token');

        $this->broadcastPush()->assertStatus(202);

        $this->assertDatabaseCount('device_tokens', 0);

        $log = NotificationLog::first();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('uninstalled', $log->failure_reason);
    }

    /**
     * The destructive path has to stay narrow. A 400 caused by our own payload
     * must not be read as "every device is dead" — that would delete a whole
     * school's tokens the first time we shipped a bad message.
     */
    public function test_a_generic_bad_request_does_not_delete_the_token()
    {
        $this->fakeFirebase(Http::response([
            'error' => [
                'code' => 400,
                'status' => 'INVALID_ARGUMENT',
                'message' => 'Invalid value at \'message.android.priority\'.',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.rpc.BadRequest',
                    'fieldViolations' => [['field' => 'message.android.priority']],
                ]],
            ],
        ], 400));

        $this->registerDevice('good-token');

        $this->broadcastPush();

        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertSame('failed', NotificationLog::first()->status);

        /*
         * And it is not retried. A settled rejection replayed three times per
         * device turns a 900-family broadcast into 2,700 requests against a
         * quota, for an answer FCM already gave.
         */
        Http::assertSentCount(2); // One token exchange, one send.
    }

    /** But a 400 that names the token field is about the token. */
    public function test_a_malformed_token_is_deleted()
    {
        $this->fakeFirebase(Http::response([
            'error' => [
                'code' => 400,
                'status' => 'INVALID_ARGUMENT',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.rpc.BadRequest',
                    'fieldViolations' => [['field' => 'message.token']],
                ]],
            ],
        ], 400));

        $this->registerDevice('malformed');

        $this->broadcastPush();

        $this->assertDatabaseCount('device_tokens', 0);
    }

    /**
     * A whole-school broadcast is ~900 sends. It must not also be ~900 OAuth
     * token exchanges — Google rate-limits those, and it would roughly double
     * the wall time of every broadcast.
     */
    public function test_the_access_token_is_minted_once_and_reused()
    {
        $this->fakeFirebase();

        $this->registerDevice('device-a');
        $this->registerDevice('device-b');
        $this->registerDevice('device-c');

        $this->broadcastPush();

        $exchanges = 0;
        Http::assertSent(function ($request) use (&$exchanges) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                $exchanges++;
            }

            return true;
        });

        $this->assertSame(1, $exchanges, 'The OAuth token should be cached across sends.');
    }

    /** An unconfigured deployment reports failure; it never claims delivery. */
    public function test_push_without_credentials_is_recorded_as_failed()
    {
        Http::fake();
        config()->set('services.fcm.credentials', null);
        $this->registerDevice('device-token-1');

        $this->broadcastPush();

        $this->assertSame('failed', NotificationLog::first()->status);
        Http::assertNothingSent();
    }

    /** Nonsense in FCM_CREDENTIALS fails closed rather than throwing. */
    public function test_unparseable_credentials_fail_closed()
    {
        Http::fake();
        config()->set('services.fcm.credentials', 'not-json-and-not-a-path');
        $this->registerDevice('device-token-1');

        $this->broadcastPush()->assertStatus(202);

        $this->assertSame('failed', NotificationLog::first()->status);
        Http::assertNothingSent();
    }

    public function test_self_test_endpoint_reports_per_device_delivery()
    {
        $this->fakeFirebase();
        $device = $this->registerDevice('device-token-1', 'ios');

        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/notifications/devices/test'))
            ->assertStatus(200)
            ->assertJsonPath('delivered', 1)
            ->assertJsonPath('devices.0.device_id', $device->id)
            ->assertJsonPath('devices.0.platform', 'ios')
            ->assertJsonPath('devices.0.status', 'sent');

        // A diagnostic ping is not messaging spend and must stay out of the
        // log the school reconciles its bill against.
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_self_test_endpoint_reports_a_failing_device_rather_than_pretending()
    {
        $this->fakeFirebase(Http::response([
            'error' => [
                'code' => 404,
                'details' => [['errorCode' => 'UNREGISTERED']],
            ],
        ], 404));

        $this->registerDevice('dead-token');

        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/notifications/devices/test'))
            ->assertStatus(502)
            ->assertJsonPath('delivered', 0)
            ->assertJsonPath('devices.0.status', 'invalid_token');

        $this->assertDatabaseCount('device_tokens', 0);
    }

    public function test_self_test_endpoint_says_so_when_no_device_is_registered()
    {
        $this->fakeFirebase();

        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/notifications/devices/test'))
            ->assertStatus(422);
    }

    /** An operator problem must not read as a client bug. */
    public function test_self_test_endpoint_returns_503_when_push_is_unconfigured()
    {
        config()->set('services.fcm.credentials', null);
        $this->registerDevice('device-token-1');

        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/notifications/devices/test'))
            ->assertStatus(503);
    }

    /** The test route can only ever reach the caller's own handsets. */
    public function test_self_test_only_touches_the_callers_own_devices()
    {
        $this->fakeFirebase();

        $this->registerDevice('parent-device');
        DeviceToken::create([
            'school_id' => $this->school->id,
            'user_id' => $this->admin->id,
            'token' => 'admin-device',
            'platform' => 'android',
        ]);

        $this->actingAs($this->parent, 'sanctum')
            ->postJson($this->url('/notifications/devices/test'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'devices');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'messages:send')
            && ($request->data()['message']['token'] ?? null) === 'admin-device');
    }
}
