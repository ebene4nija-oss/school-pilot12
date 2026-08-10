<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolPaymentGateway;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Each school's own merchant account — gaps G8 and G9.
 *
 * Before this, gateway credentials were a single platform-wide pair in config,
 * so every school's fees settled into one SchoolPilot account; no endpoint
 * exposed a key of any kind, so no client could open a checkout at all; and the
 * one payment endpoint that existed required the *client* to invent a unique
 * reference.
 *
 * The tests that matter most here are the negative ones. A school must not be
 * able to have its own secret accepted as the signature on another school's
 * payment, and a key must never come back out of the API.
 */
class SchoolPaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_gracelandsecretkey';

    private School $school;
    private User $admin;
    private User $parent;
    private Student $student;
    private Invoice $invoice;

    /**
     * What the faked Paystack returns.
     *
     * Held in a property and read through a closure because `Http::fake()`
     * merges stubs rather than replacing them — a second `fake()` call inside a
     * test would lose to the one registered here, and the test would silently
     * assert against the happy path it meant to replace.
     */
    private $paystackResponse;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://graceland.localhost');

        Http::preventStrayRequests();
        $this->fakePaystackCheckout();

        $this->school = School::create([
            'name' => 'Graceland College', 'slug' => 'graceland', 'subdomain' => 'graceland',
        ]);

        $this->admin = $this->user('Bursar', 'bursar@graceland.test', 'school_admin');
        $this->parent = $this->user('Mrs Okeke', 'okeke@graceland.test', 'parent');

        $session = AcademicSession::create([
            'school_id' => $this->school->id, 'name' => '2026/2027',
            'start_date' => '2026-09-01', 'end_date' => '2027-07-31',
        ]);

        $term = Term::create([
            'school_id' => $this->school->id, 'session_id' => $session->id,
            'name' => 'First Term', 'start_date' => '2026-09-14', 'end_date' => '2026-12-11',
        ]);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);

        $this->student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $this->user('Chidi Okeke', 'chidi@graceland.test', 'student')->id,
            'class_id' => $class->id,
            'admission_number' => 'GC/2026/010',
        ]);

        $guardian = Guardian::create([
            'school_id' => $this->school->id,
            'user_id' => $this->parent->id,
            'relationship' => 'mother',
        ]);
        $guardian->students()->attach($this->student->id, ['is_primary' => true]);

        $this->invoice = Invoice::create([
            'school_id' => $this->school->id,
            'term_id' => $term->id,
            'student_id' => $this->student->id,
            'invoice_number' => 'INV-000001',
            'total_amount' => 45000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
    }

    private function fakePaystackCheckout(): void
    {
        $this->paystackResponse = Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/test-checkout',
                'access_code' => 'test-access-code',
            ],
        ]);

        Http::fake(['api.paystack.co/*' => fn () => $this->paystackResponse]);
    }

    private function user(string $name, string $email, string $role, ?School $school = null): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);

        UserProfile::create([
            'user_id' => $user->id,
            'school_id' => $school?->id ?? $this->school->id,
            'role' => $role,
        ]);

        return $user;
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    private function connect(?School $school = null, string $secret = self::SECRET): SchoolPaymentGateway
    {
        return SchoolPaymentGateway::create([
            'school_id' => ($school ?? $this->school)->id,
            'gateway' => 'paystack',
            'secret_key' => $secret,
            'public_key' => 'pk_test_gracelandpublickey',
            'secret_last4' => substr($secret, -4),
            'mode' => 'test',
            'is_active' => true,
        ]);
    }

    private function postPaystackWebhook(string $reference, int $amountKobo, string $secret)
    {
        $body = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => $reference, 'amount' => $amountKobo],
        ]);

        return $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, $secret),
        ], $body);
    }

    // ------------------------------------------------- Connecting an account

    public function test_a_school_admin_connects_their_own_paystack_account()
    {
        $this->as($this->admin)
            ->putJson('/api/v1/finance/gateways/paystack', [
                'secret_key' => self::SECRET,
                'public_key' => 'pk_test_gracelandpublickey',
            ])
            ->assertOk()
            ->assertJsonPath('mode', 'test')
            ->assertJsonPath('secret_key_hint', '••••tkey');

        $this->assertDatabaseHas('school_payment_gateways', [
            'school_id' => $this->school->id,
            'gateway' => 'paystack',
            'secret_last4' => 'tkey',
        ]);
    }

    /** A stolen database dump must not be a set of live merchant keys. */
    public function test_the_secret_is_ciphertext_at_rest()
    {
        $this->connect();

        $stored = DB::table('school_payment_gateways')->value('secret_key');

        $this->assertNotSame(self::SECRET, $stored);
        $this->assertStringNotContainsString(self::SECRET, $stored);

        // And still usable, so this is encryption rather than corruption.
        $this->assertSame(self::SECRET, SchoolPaymentGateway::allTenants()->first()->secret_key);
    }

    /** The key is write-only across the API — recognisable, never readable. */
    public function test_no_endpoint_ever_hands_a_key_back()
    {
        $this->connect();

        $body = $this->as($this->admin)->getJson('/api/v1/finance/gateways')->assertOk()->getContent();

        $this->assertStringNotContainsString(self::SECRET, $body);
        $this->assertStringNotContainsString('pk_test_gracelandpublickey', $body);

        // Recognisable without being readable.
        $this->assertSame('••••tkey', json_decode($body, true)['data'][0]['secret_key_hint']);
    }

    public function test_the_settings_screen_reports_the_webhook_url_to_paste_into_the_dashboard()
    {
        $this->as($this->admin)
            ->getJson('/api/v1/finance/gateways')
            ->assertOk()
            ->assertJsonPath('data.0.gateway', 'paystack')
            ->assertJsonPath('data.0.connected', false)
            ->assertJsonPath('data.0.webhook_url', 'http://graceland.localhost/api/v1/webhooks/paystack');
    }

    /**
     * The single most common way this is misconfigured: the public key pasted
     * into the secret field. It authenticates fine at save time and fails at
     * the first real payment.
     */
    public function test_a_public_key_in_the_secret_field_is_refused()
    {
        $this->as($this->admin)
            ->putJson('/api/v1/finance/gateways/paystack', [
                'secret_key' => 'pk_test_gracelandpublickey',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('secret_key');

        $this->assertDatabaseCount('school_payment_gateways', 0);
    }

    public function test_flutterwave_cannot_be_connected_without_its_dashboard_hash()
    {
        // Without it not one Flutterwave callback could ever be verified, so
        // every payment through the account would strand the parent who made it.
        $this->as($this->admin)
            ->putJson('/api/v1/finance/gateways/flutterwave', [
                'secret_key' => 'FLWSECK_TEST-abcdef123456',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('webhook_secret');
    }

    public function test_live_keys_are_reported_as_live()
    {
        $this->as($this->admin)
            ->putJson('/api/v1/finance/gateways/paystack', [
                'secret_key' => 'sk_live_gracelandliveaccount',
            ])
            ->assertOk()
            ->assertJsonPath('mode', 'live');
    }

    public function test_a_parent_or_teacher_cannot_touch_the_merchant_account()
    {
        $teacher = $this->user('Teacher', 'teacher@graceland.test', 'teacher');

        foreach ([$this->parent, $teacher] as $user) {
            $this->as($user)->getJson('/api/v1/finance/gateways')->assertStatus(403);
            $this->as($user)
                ->putJson('/api/v1/finance/gateways/paystack', ['secret_key' => self::SECRET])
                ->assertStatus(403);
        }
    }

    /**
     * A SchoolPilot operator sells the school software. They do not get to
     * decide which bank account another organisation's fee income lands in.
     */
    public function test_a_platform_operator_cannot_set_a_schools_merchant_account()
    {
        $platform = User::create(['name' => 'Ops', 'email' => 'ops@schoolpilot.test', 'password' => bcrypt('password')]);
        UserProfile::create(['user_id' => $platform->id, 'school_id' => null, 'role' => 'super_admin']);

        $this->as($platform)
            ->putJson('/api/v1/finance/gateways/paystack', ['secret_key' => self::SECRET])
            ->assertStatus(403);
    }

    public function test_disconnecting_removes_the_key()
    {
        $this->connect();

        $this->as($this->admin)->deleteJson('/api/v1/finance/gateways/paystack')->assertOk();

        $this->assertDatabaseCount('school_payment_gateways', 0);
    }

    // ------------------------------------------------------------- Checkout

    public function test_a_parent_gets_a_hosted_checkout_url_and_never_a_key()
    {
        $this->connect();

        $response = $this->as($this->parent)
            ->postJson('/api/v1/finance/payments/initialize', [
                'invoice_id' => $this->invoice->id,
                'gateway' => 'paystack',
            ])
            ->assertStatus(201)
            ->assertJsonPath('authorization_url', 'https://checkout.paystack.com/test-checkout')
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.currency', 'NGN');

        // The whole balance, computed by the server rather than sent by the app.
        $this->assertSame('45000.00', $response->json('payment.amount'));
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());

        // Nothing is paid until the webhook says so.
        $this->assertSame('unpaid', $this->invoice->fresh()->status);
    }

    /** Gap G9: the reference is the server's, and it is opaque to the client. */
    public function test_the_server_mints_the_reference()
    {
        $this->connect();

        $reference = $this->as($this->parent)
            ->postJson('/api/v1/finance/payments/initialize', [
                'invoice_id' => $this->invoice->id,
                'gateway' => 'paystack',
            ])->json('payment.reference');

        $this->assertStringStartsWith('SPFP_', $reference);
        $this->assertDatabaseHas('payments', ['reference' => $reference, 'status' => 'pending']);
    }

    public function test_the_charge_reaches_paystack_in_kobo_on_the_schools_own_key()
    {
        $this->connect();

        $this->as($this->parent)->postJson('/api/v1/finance/payments/initialize', [
            'invoice_id' => $this->invoice->id,
            'gateway' => 'paystack',
        ])->assertStatus(201);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.paystack.co')
                // ₦45,000 is 4,500,000 kobo. Sending naira here would charge
                // the parent ₦450 and reconcile as a mystery underpayment.
                && $request['amount'] === 4500000
                && $request['currency'] === 'NGN'
                && $request->hasHeader('Authorization', 'Bearer ' . self::SECRET);
        });
    }

    public function test_a_school_that_has_not_connected_an_account_cannot_take_online_payment()
    {
        $this->as($this->parent)
            ->postJson('/api/v1/finance/payments/initialize', [
                'invoice_id' => $this->invoice->id,
                'gateway' => 'paystack',
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('payments', 0);
    }

    /** School membership is not authorization: this is somebody else's bill. */
    public function test_a_parent_cannot_open_a_checkout_against_another_familys_invoice()
    {
        $this->connect();

        $stranger = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $this->user('Stranger', 'stranger@graceland.test', 'student')->id,
            'admission_number' => 'GC/2026/099',
        ]);

        $theirInvoice = Invoice::create([
            'school_id' => $this->school->id,
            'term_id' => $this->invoice->term_id,
            'student_id' => $stranger->id,
            'invoice_number' => 'INV-000002',
            'total_amount' => 60000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);

        $this->as($this->parent)
            ->postJson('/api/v1/finance/payments/initialize', [
                'invoice_id' => $theirInvoice->id,
                'gateway' => 'paystack',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_part_payment_cannot_exceed_the_outstanding_balance()
    {
        $this->connect();

        $this->as($this->parent)
            ->postJson('/api/v1/finance/payments/initialize', [
                'invoice_id' => $this->invoice->id,
                'gateway' => 'paystack',
                'amount' => 50000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_a_settled_invoice_cannot_be_paid_again()
    {
        $this->connect();
        $this->invoice->update(['amount_paid' => 45000, 'status' => 'paid']);

        $this->as($this->parent)
            ->postJson('/api/v1/finance/payments/initialize', [
                'invoice_id' => $this->invoice->id,
                'gateway' => 'paystack',
            ])
            ->assertStatus(409);
    }

    /**
     * A pending payment nobody ever opened a checkout for is a row the bursar
     * has to explain and nothing can reconcile.
     */
    public function test_a_refused_gateway_leaves_no_orphan_pending_payment()
    {
        $this->connect();

        $this->paystackResponse = Http::response(['status' => false, 'message' => 'Invalid key'], 401);

        $this->as($this->parent)
            ->postJson('/api/v1/finance/payments/initialize', [
                'invoice_id' => $this->invoice->id,
                'gateway' => 'paystack',
            ])
            ->assertStatus(502)
            ->assertJsonPath('message', "The school's payment gateway rejected this transaction: Invalid key");

        $this->assertDatabaseCount('payments', 0);
    }

    // -------------------------------------------------------- Settlement

    public function test_a_callback_signed_with_the_schools_own_key_settles_the_invoice()
    {
        $this->connect();

        $reference = $this->as($this->parent)
            ->postJson('/api/v1/finance/payments/initialize', [
                'invoice_id' => $this->invoice->id,
                'gateway' => 'paystack',
            ])->json('payment.reference');

        $this->postPaystackWebhook($reference, 45000_00, self::SECRET)->assertOk();

        $this->assertSame('successful', Payment::first()->status);
        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    /**
     * The platform key must not settle a payment made into a school's own
     * account — otherwise connecting an account would *weaken* verification by
     * leaving a second valid signer.
     */
    public function test_the_platform_key_cannot_settle_a_connected_schools_payment()
    {
        config(['services.paystack.secret' => 'platform_secret']);
        $this->connect();

        $reference = $this->as($this->parent)
            ->postJson('/api/v1/finance/payments/initialize', [
                'invoice_id' => $this->invoice->id,
                'gateway' => 'paystack',
            ])->json('payment.reference');

        $this->postPaystackWebhook($reference, 45000_00, 'platform_secret')->assertStatus(400);

        $this->assertSame('pending', Payment::first()->status);
        $this->assertSame('unpaid', $this->invoice->fresh()->status);
    }

    /**
     * The cross-tenant forgery per-school keys create if the school is resolved
     * from the request host: a school admin can reach their own subdomain, so
     * resolving that way would let them sign a neighbouring school's payment
     * with their own secret and mark it paid.
     */
    public function test_one_school_cannot_sign_another_schools_payment_with_its_own_key()
    {
        $this->connect();

        $rival = School::create(['name' => 'Rival', 'slug' => 'rival', 'subdomain' => 'rival']);
        $this->connect($rival, 'sk_test_rivalschoolsecret');

        $reference = $this->as($this->parent)
            ->postJson('/api/v1/finance/payments/initialize', [
                'invoice_id' => $this->invoice->id,
                'gateway' => 'paystack',
            ])->json('payment.reference');

        // Graceland's payment, signed by Rival, delivered to Rival's host.
        $body = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => $reference, 'amount' => 45000_00],
        ]);

        $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_HOST' => 'rival.localhost',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_rivalschoolsecret'),
        ], $body)->assertStatus(400);

        $this->assertSame('pending', Payment::first()->status);
        $this->assertSame('unpaid', $this->invoice->fresh()->status);
    }

    /**
     * Backwards compatibility. Every school deployed before per-school accounts
     * existed has no credentials of its own and must keep settling exactly as
     * it did.
     */
    public function test_a_school_without_its_own_account_still_settles_on_the_platform_key()
    {
        config(['services.paystack.secret' => 'platform_secret']);

        $payment = Payment::create([
            'school_id' => $this->school->id,
            'invoice_id' => $this->invoice->id,
            'reference' => 'SPFP_LEGACY_REFERENCE',
            'amount' => 45000,
            'gateway' => 'paystack',
            'status' => 'pending',
        ]);

        $this->postPaystackWebhook($payment->reference, 45000_00, 'platform_secret')->assertOk();

        $this->assertSame('successful', $payment->fresh()->status);
        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    // ------------------------------------------ Recording a payment (gap G9)

    public function test_a_recorded_payment_gets_a_server_reference_when_none_is_given()
    {
        $this->as($this->admin)
            ->postJson('/api/v1/finance/payments', [
                'invoice_id' => $this->invoice->id,
                'amount' => 45000,
                'gateway' => 'cash',
            ])
            ->assertOk();

        $payment = Payment::first();

        $this->assertStringStartsWith('SPMP_', $payment->reference);
        $this->assertSame('successful', $payment->status);
    }

    /** A bursar transcribing a bank teller's slip number keeps it. */
    public function test_an_admin_may_still_supply_a_real_world_reference_for_a_manual_payment()
    {
        $this->as($this->admin)
            ->postJson('/api/v1/finance/payments', [
                'invoice_id' => $this->invoice->id,
                'amount' => 45000,
                'gateway' => 'bank_transfer',
                'reference' => 'GTB/2026/889231',
            ])
            ->assertOk();

        $this->assertSame('GTB/2026/889231', Payment::first()->reference);
    }

    /**
     * The fragility G9 names: a client that mints its own reference retries
     * with a fresh one and creates a second pending row against the same money.
     * A parent no longer gets to choose it.
     */
    public function test_a_parent_supplied_reference_is_ignored()
    {
        $this->as($this->parent)
            ->postJson('/api/v1/finance/payments', [
                'invoice_id' => $this->invoice->id,
                'amount' => 45000,
                'gateway' => 'bank_transfer',
                'reference' => 'I_PICKED_THIS',
            ])
            ->assertOk();

        $payment = Payment::first();

        $this->assertNotSame('I_PICKED_THIS', $payment->reference);
        $this->assertStringStartsWith('SPMP_', $payment->reference);
        // And a parent still cannot declare their own payment successful.
        $this->assertSame('pending', $payment->status);
    }
}
