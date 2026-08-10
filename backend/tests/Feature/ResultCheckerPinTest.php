<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Guardian;
use App\Models\ResultPin;
use App\Models\ResultPinBatch;
use App\Models\ResultPinPriceTier;
use App\Models\ResultPinSale;
use App\Models\ResultRelease;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolPaymentGateway;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ResultPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Result-checker PINs, end to end.
 *
 * The two money flows are tested separately because they fail differently: a
 * broken wholesale flow costs SchoolPilot revenue, a broken retail flow strands
 * a parent who has already paid.
 */
class ResultCheckerPinTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $admin;
    protected User $platformAdmin;
    protected User $parent;
    protected Student $student;
    protected Term $term;
    protected SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://graceland.localhost');

        /*
         * Checkout is opened server-side now, so a purchase reaches out to
         * Paystack. Faked, and stray requests prevented outright — a test suite
         * that can quietly talk to a live payment gateway is a test suite that
         * will one day charge somebody.
         */
        Http::preventStrayRequests();
        Http::fake([
            'api.paystack.co/*' => Http::response([
                'status' => true,
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/test-checkout',
                    'access_code' => 'test-access-code',
                ],
            ]),
        ]);

        $this->school = School::create([
            'name' => 'Graceland College',
            'slug' => 'graceland',
            'subdomain' => 'graceland',
        ]);

        $this->admin = $this->makeUser('bello@graceland.test', 'school_admin', $this->school->id);
        $this->parent = $this->makeUser('mrsokeke@graceland.test', 'parent', $this->school->id);

        // SchoolPilot staff. Belongs to no school.
        $this->platformAdmin = $this->makeUser('ops@schoolpilot.test', 'super_admin', null);

        $session = AcademicSession::create([
            'school_id' => $this->school->id,
            'name' => '2026/2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-07-31',
        ]);

        $this->term = Term::create([
            'school_id' => $this->school->id,
            'session_id' => $session->id,
            'name' => 'First Term',
            'start_date' => '2026-09-14',
            'end_date' => '2026-12-11',
        ]);

        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);

        $studentUser = $this->makeUser('chidi@graceland.test', 'student', $this->school->id);

        $this->student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $studentUser->id,
            'class_id' => $this->class->id,
            'admission_number' => 'GC/2026/010',
        ]);

        $guardian = Guardian::create([
            'school_id' => $this->school->id,
            'user_id' => $this->parent->id,
            'relationship' => 'mother',
        ]);
        $guardian->students()->attach($this->student->id, ['is_primary' => true]);

        $subject = Subject::create(['school_id' => $this->school->id, 'name' => 'Mathematics', 'code' => 'MTH']);

        ScoreEntry::create([
            'school_id' => $this->school->id,
            'term_id' => $this->term->id,
            'student_id' => $this->student->id,
            'subject_id' => $subject->id,
            'first_ca' => 17,
            'second_ca' => 16,
            'exam' => 51,
            'total_score' => 84,
            'grade' => 'A1',
            'ai_comment_status' => 'none',
        ]);

        ResultPinPriceTier::create(['min_quantity' => 1, 'unit_price' => 100.00, 'is_active' => true]);
        ResultPinPriceTier::create(['min_quantity' => 500, 'unit_price' => 80.00, 'is_active' => true]);
    }

    private function makeUser(string $email, string $role, ?int $schoolId): User
    {
        $user = User::create([
            'name' => explode('@', $email)[0],
            'email' => $email,
            'password' => bcrypt('password'),
        ]);

        UserProfile::create(['school_id' => $schoolId, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum')
            ->withServerVariables(['HTTP_HOST' => 'graceland.localhost']);
    }

    /**
     * Switch the paywall on — and connect a merchant account, because since
     * gap G8 was closed a school cannot sell a result online without one.
     */
    private function enableChecker(float $retail = 500.00): void
    {
        $this->school->update([
            'result_checker_enabled' => true,
            'result_pin_retail_price' => $retail,
        ]);

        $this->connectPaystack();
    }

    private function connectPaystack(string $secret = 'sk_test_gracelandsecretkey'): SchoolPaymentGateway
    {
        return SchoolPaymentGateway::create([
            'school_id' => $this->school->id,
            'gateway' => 'paystack',
            'secret_key' => $secret,
            'public_key' => 'pk_test_gracelandpublickey',
            'secret_last4' => substr($secret, -4),
            'mode' => 'test',
            'is_active' => true,
        ]);
    }

    private function releaseResults(): void
    {
        ResultRelease::create([
            'school_id' => $this->school->id,
            'term_id' => $this->term->id,
            'class_id' => null,
            'is_released' => true,
            'released_by' => $this->admin->id,
            'released_at' => now(),
        ]);
    }

    private function grantStock(int $quantity = 10): ResultPinBatch
    {
        $batch = ResultPinBatch::create([
            'school_id' => $this->school->id,
            'reference' => 'SPGB_TEST_' . $quantity,
            'quantity' => $quantity,
            'unit_price' => 100,
            'total_amount' => 100 * $quantity,
            'source' => 'manual_grant',
            'status' => 'active',
            'paid_at' => now(),
        ]);

        app(ResultPinService::class)->mintPins($batch, $quantity);

        return $batch;
    }

    // ==================================================================
    // Release gate — nothing is sold before the result is ready
    // ==================================================================

    public function test_summary_reports_not_released_before_the_school_releases()
    {
        $this->enableChecker();

        $this->as($this->parent)
            ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}/summary")
            ->assertOk()
            ->assertJsonPath('status', 'not_released');
    }

    public function test_a_guardian_cannot_buy_a_pin_before_results_are_released()
    {
        $this->enableChecker();
        $this->grantStock();

        $this->as($this->parent)
            ->postJson('/api/v1/result-checker/purchase', [
                'student_id' => $this->student->id,
                'term_id' => $this->term->id,
                'gateway' => 'paystack',
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('result_pin_sales', 0);
    }

    public function test_the_full_result_is_unavailable_before_release_even_with_a_pin()
    {
        $this->enableChecker();
        $this->grantStock();
        app(ResultPinService::class)->waive(
            $this->school->id, $this->student->id, $this->term->id, $this->admin->id, 'test'
        );

        $this->as($this->parent)
            ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}")
            ->assertStatus(404);
    }

    // ==================================================================
    // The paywall
    // ==================================================================

    public function test_the_free_summary_shows_the_headline_without_a_pin()
    {
        $this->enableChecker();
        $this->releaseResults();

        $this->as($this->parent)
            ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}/summary")
            ->assertOk()
            ->assertJsonPath('status', 'released')
            ->assertJsonPath('unlocked', false)
            ->assertJsonPath('preview.average', 84)
            ->assertJsonPath('preview.subjects_count', 1)
            // The thing being sold must not leak through the free tier.
            ->assertJsonMissingPath('scores');
    }

    public function test_the_full_result_is_paywalled_without_a_pin()
    {
        $this->enableChecker();
        $this->releaseResults();

        $this->as($this->parent)
            ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}")
            ->assertStatus(402)
            ->assertJsonPath('unlock_options.online_purchase.available', true)
            ->assertJsonPath('unlock_options.online_purchase.amount', '500.00');
    }

    public function test_a_school_that_has_not_enabled_the_checker_is_not_paywalled()
    {
        // Every school predating this feature must keep working exactly as it
        // did — switching on a paywall nobody agreed to would be a breach.
        $this->releaseResults();

        $this->as($this->parent)
            ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}")
            ->assertOk();
    }

    public function test_staff_report_card_access_is_never_metered()
    {
        $this->enableChecker();
        $this->releaseResults();

        // No PIN anywhere, yet the admin's own route still renders.
        $this->as($this->admin)
            ->get("/api/v1/report-cards/{$this->student->id}/{$this->term->id}")
            ->assertOk();

        $this->assertDatabaseCount('result_pins', 0);
    }

    // ==================================================================
    // Redeeming a PIN bought over the counter
    // ==================================================================

    public function test_a_counter_sold_pin_unlocks_the_result()
    {
        $this->enableChecker();
        $this->releaseResults();
        $this->grantStock(3);

        $sale = $this->as($this->admin)
            ->postJson('/api/v1/result-pins/counter-sale', ['amount' => 500])
            ->assertStatus(201)
            ->json();

        $this->as($this->parent)
            ->postJson('/api/v1/result-checker/redeem', [
                'pin' => $sale['pin'],
                'student_id' => $this->student->id,
                'term_id' => $this->term->id,
            ])
            ->assertOk()
            ->assertJsonPath('views_remaining', 5);

        $this->as($this->parent)
            ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}")
            ->assertOk();
    }

    public function test_a_pin_is_accepted_with_spaces_and_lowercase()
    {
        $this->enableChecker();
        $this->releaseResults();
        $this->grantStock(1);

        $code = ResultPin::first()->revealCode();

        $this->as($this->parent)
            ->postJson('/api/v1/result-checker/redeem', [
                // What a parent reading off a printed slip actually types.
                'pin' => ' ' . strtolower(str_replace('-', ' ', $code)) . ' ',
                'student_id' => $this->student->id,
                'term_id' => $this->term->id,
            ])
            ->assertOk();
    }

    public function test_a_pin_cannot_be_reused_for_a_second_child()
    {
        $this->enableChecker();
        $this->releaseResults();
        $this->grantStock(1);

        $sibling = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $this->makeUser('ada@graceland.test', 'student', $this->school->id)->id,
            'class_id' => $this->class->id,
            'admission_number' => 'GC/2026/011',
        ]);
        Guardian::where('user_id', $this->parent->id)->first()->students()->attach($sibling->id);

        $code = ResultPin::first()->revealCode();

        $this->as($this->parent)->postJson('/api/v1/result-checker/redeem', [
            'pin' => $code,
            'student_id' => $this->student->id,
            'term_id' => $this->term->id,
        ])->assertOk();

        $this->as($this->parent)->postJson('/api/v1/result-checker/redeem', [
            'pin' => $code,
            'student_id' => $sibling->id,
            'term_id' => $this->term->id,
        ])->assertStatus(422);
    }

    public function test_re_entering_your_own_pin_is_a_no_op_not_an_error()
    {
        $this->enableChecker();
        $this->releaseResults();
        $this->grantStock(1);

        $code = ResultPin::first()->revealCode();

        foreach ([1, 2] as $_) {
            $this->as($this->parent)->postJson('/api/v1/result-checker/redeem', [
                'pin' => $code,
                'student_id' => $this->student->id,
                'term_id' => $this->term->id,
            ])->assertOk();
        }

        $this->assertSame(1, ResultPin::where('status', 'used')->count());
    }

    public function test_a_pin_from_another_school_is_rejected()
    {
        $this->enableChecker();
        $this->releaseResults();

        $other = School::create(['name' => 'Rival Academy', 'slug' => 'rival', 'subdomain' => 'rival']);
        $otherBatch = ResultPinBatch::create([
            'school_id' => $other->id,
            'reference' => 'SPGB_OTHER',
            'quantity' => 1,
            'unit_price' => 100,
            'total_amount' => 100,
            'source' => 'manual_grant',
            'status' => 'active',
        ]);
        $codes = app(ResultPinService::class)->mintPins($otherBatch, 1);

        $this->as($this->parent)->postJson('/api/v1/result-checker/redeem', [
            'pin' => $codes[0]['pin'],
            'student_id' => $this->student->id,
            'term_id' => $this->term->id,
        ])->assertStatus(422);
    }

    public function test_an_invalid_pin_is_rejected()
    {
        $this->enableChecker();
        $this->releaseResults();

        $this->as($this->parent)->postJson('/api/v1/result-checker/redeem', [
            'pin' => 'ZZZZ-ZZZZ-ZZZZ',
            'student_id' => $this->student->id,
            'term_id' => $this->term->id,
        ])->assertStatus(422);
    }

    // ==================================================================
    // View metering
    // ==================================================================

    public function test_a_pin_allows_five_views_then_stops()
    {
        $this->enableChecker();
        $this->releaseResults();
        $this->grantStock(1);

        $code = ResultPin::first()->revealCode();
        $this->as($this->parent)->postJson('/api/v1/result-checker/redeem', [
            'pin' => $code,
            'student_id' => $this->student->id,
            'term_id' => $this->term->id,
        ])->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->as($this->parent)
                ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}")
                ->assertOk();
        }

        $this->as($this->parent)
            ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}")
            ->assertStatus(402);

        $this->assertSame(5, ResultPin::first()->views_used);
    }

    // ==================================================================
    // Guardian's online purchase — settled only by the signed webhook
    // ==================================================================

    public function test_initiating_a_purchase_unlocks_nothing_until_the_webhook_confirms()
    {
        $this->enableChecker();
        $this->releaseResults();
        $this->grantStock(2);

        $this->as($this->parent)
            ->postJson('/api/v1/result-checker/purchase', [
                'student_id' => $this->student->id,
                'term_id' => $this->term->id,
                'gateway' => 'paystack',
            ])
            ->assertStatus(201)
            ->assertJsonPath('sale.amount', '500.00');

        // Paid for, but not yet paid. Still locked.
        $this->as($this->parent)
            ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}")
            ->assertStatus(402);
    }

    public function test_the_paystack_webhook_allocates_a_pin_and_unlocks_the_result()
    {
        $this->enableChecker();
        $this->releaseResults();
        $this->grantStock(2);

        $reference = $this->as($this->parent)
            ->postJson('/api/v1/result-checker/purchase', [
                'student_id' => $this->student->id,
                'term_id' => $this->term->id,
                'gateway' => 'paystack',
            ])->json('sale.reference');

        $this->postPaystackWebhook($reference, 500_00);

        $this->assertSame('successful', ResultPinSale::first()->status);
        $this->assertSame(1, ResultPin::where('status', 'used')->count());
        // Stock consumed, not conjured.
        $this->assertSame(1, ResultPin::where('status', 'available')->count());

        $this->as($this->parent)
            ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}")
            ->assertOk();
    }

    public function test_an_unsigned_webhook_cannot_unlock_a_result()
    {
        $this->enableChecker();
        $this->releaseResults();
        $this->grantStock(2);

        $reference = $this->as($this->parent)
            ->postJson('/api/v1/result-checker/purchase', [
                'student_id' => $this->student->id,
                'term_id' => $this->term->id,
                'gateway' => 'paystack',
            ])->json('sale.reference');

        config(['services.paystack.secret' => 'test_secret']);

        $this->postJson('/api/v1/webhooks/paystack', [
            'event' => 'charge.success',
            'data' => ['reference' => $reference, 'amount' => 500_00],
        ], ['x-paystack-signature' => 'forged'])->assertStatus(400);

        $this->assertSame('pending', ResultPinSale::first()->status);
        $this->assertSame(0, ResultPin::where('status', 'used')->count());
    }

    public function test_an_underpaid_purchase_does_not_unlock_the_result()
    {
        $this->enableChecker();
        $this->releaseResults();
        $this->grantStock(2);

        $reference = $this->as($this->parent)
            ->postJson('/api/v1/result-checker/purchase', [
                'student_id' => $this->student->id,
                'term_id' => $this->term->id,
                'gateway' => 'paystack',
            ])->json('sale.reference');

        // Signed correctly, but for ₦1 against a ₦500 price.
        $this->postPaystackWebhook($reference, 1_00);

        $this->assertSame('pending', ResultPinSale::first()->status);
        $this->assertSame(0, ResultPin::where('status', 'used')->count());
    }

    public function test_a_replayed_webhook_does_not_allocate_a_second_pin()
    {
        $this->enableChecker();
        $this->releaseResults();
        $this->grantStock(3);

        $reference = $this->as($this->parent)
            ->postJson('/api/v1/result-checker/purchase', [
                'student_id' => $this->student->id,
                'term_id' => $this->term->id,
                'gateway' => 'paystack',
            ])->json('sale.reference');

        $this->postPaystackWebhook($reference, 500_00);
        $this->postPaystackWebhook($reference, 500_00);

        $this->assertSame(1, ResultPin::where('status', 'used')->count());
        $this->assertSame(2, ResultPin::where('status', 'available')->count());
    }

    public function test_a_paid_purchase_is_honoured_even_when_the_school_ran_out_of_stock()
    {
        $this->enableChecker();
        $this->releaseResults();
        // Deliberately no stock at all.

        $reference = $this->as($this->parent)
            ->postJson('/api/v1/result-checker/purchase', [
                'student_id' => $this->student->id,
                'term_id' => $this->term->id,
                'gateway' => 'paystack',
            ])->json('sale.reference');

        $this->postPaystackWebhook($reference, 500_00);

        // The parent paid the school; the school owes SchoolPilot. The parent
        // is not the one left holding the problem.
        $this->as($this->parent)
            ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}")
            ->assertOk();

        $this->assertSame(1, ResultPin::where('origin', 'overdraft')->count());
    }

    public function test_a_guardian_cannot_buy_a_pin_for_a_child_who_is_not_theirs()
    {
        $this->enableChecker();
        $this->releaseResults();

        $stranger = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $this->makeUser('stranger@graceland.test', 'student', $this->school->id)->id,
            'class_id' => $this->class->id,
            'admission_number' => 'GC/2026/099',
        ]);

        $this->as($this->parent)
            ->postJson('/api/v1/result-checker/purchase', [
                'student_id' => $stranger->id,
                'term_id' => $this->term->id,
                'gateway' => 'paystack',
            ])
            ->assertStatus(403);
    }

    public function test_a_guardian_is_not_charged_twice_for_the_same_result()
    {
        $this->enableChecker();
        $this->releaseResults();
        $this->grantStock(3);

        app(ResultPinService::class)->waive(
            $this->school->id, $this->student->id, $this->term->id, $this->admin->id, 'already paid at office'
        );

        $this->as($this->parent)
            ->postJson('/api/v1/result-checker/purchase', [
                'student_id' => $this->student->id,
                'term_id' => $this->term->id,
                'gateway' => 'paystack',
            ])
            ->assertStatus(409);
    }

    // ==================================================================
    // School buying stock from SchoolPilot
    // ==================================================================

    public function test_a_school_buying_a_batch_gets_no_pins_until_payment_confirms()
    {
        $reference = $this->as($this->admin)
            ->postJson('/api/v1/result-pins/batches', ['quantity' => 500, 'gateway' => 'paystack'])
            ->assertStatus(201)
            // 500 hits the volume band: ₦80, not ₦100.
            ->assertJsonPath('batch.unit_price', '80.00')
            ->assertJsonPath('batch.total_amount', '40000.00')
            ->json('batch.reference');

        $this->assertDatabaseCount('result_pins', 0);

        $this->postPaystackWebhook($reference, 40000_00);

        $this->assertSame('active', ResultPinBatch::first()->status);
        $this->assertSame(500, ResultPin::where('status', 'available')->count());
    }

    public function test_an_underpaid_batch_mints_no_pins()
    {
        $reference = $this->as($this->admin)
            ->postJson('/api/v1/result-pins/batches', ['quantity' => 500, 'gateway' => 'paystack'])
            ->json('batch.reference');

        $this->postPaystackWebhook($reference, 100_00);

        $this->assertSame('pending', ResultPinBatch::first()->status);
        $this->assertDatabaseCount('result_pins', 0);
    }

    public function test_a_replayed_batch_webhook_does_not_double_mint()
    {
        $reference = $this->as($this->admin)
            ->postJson('/api/v1/result-pins/batches', ['quantity' => 10, 'gateway' => 'paystack'])
            ->json('batch.reference');

        $this->postPaystackWebhook($reference, 1000_00);
        $this->postPaystackWebhook($reference, 1000_00);

        $this->assertSame(10, ResultPin::count());
    }

    // ==================================================================
    // SchoolPilot granting PINs for an offline payment
    // ==================================================================

    public function test_schoolpilot_can_grant_pins_to_a_school_that_paid_by_bank_transfer()
    {
        $response = $this->as($this->platformAdmin)
            ->postJson('/api/v1/platform/result-pins/grant', [
                'school_id' => $this->school->id,
                'quantity' => 200,
                'notes' => 'GTB transfer 08/08, ref 998877. Director called.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('batch.source', 'manual_grant');

        $this->assertCount(200, $response->json('pins'));
        $this->assertSame(200, ResultPin::where('status', 'available')->count());

        // Immediately sellable, with no gateway ever involved.
        $this->as($this->admin)
            ->getJson('/api/v1/result-pins/inventory')
            ->assertOk()
            ->assertJsonPath('available', 200);
    }

    public function test_a_granted_batch_can_use_a_negotiated_price()
    {
        $this->as($this->platformAdmin)
            ->postJson('/api/v1/platform/result-pins/grant', [
                'school_id' => $this->school->id,
                'quantity' => 100,
                'unit_price' => 60,
                'notes' => 'Negotiated rate for a founding school.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('batch.unit_price', '60.00')
            ->assertJsonPath('batch.total_amount', '6000.00');
    }

    public function test_a_school_admin_cannot_grant_themselves_pins()
    {
        $this->as($this->admin)
            ->postJson('/api/v1/platform/result-pins/grant', [
                'school_id' => $this->school->id,
                'quantity' => 1000,
                'notes' => 'free pins please',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('result_pins', 0);
    }

    public function test_a_school_admin_cannot_set_the_platform_rate_card()
    {
        $this->as($this->admin)
            ->postJson('/api/v1/platform/result-pins/price-tiers', [
                'min_quantity' => 1,
                'unit_price' => 0,
            ])
            ->assertStatus(403);
    }

    // ==================================================================
    // Tenant isolation
    // ==================================================================

    public function test_a_school_cannot_see_or_reveal_another_schools_pins()
    {
        $other = School::create(['name' => 'Rival Academy', 'slug' => 'rival', 'subdomain' => 'rival']);
        $otherBatch = ResultPinBatch::create([
            'school_id' => $other->id,
            'reference' => 'SPGB_RIVAL',
            'quantity' => 5,
            'unit_price' => 100,
            'total_amount' => 500,
            'source' => 'manual_grant',
            'status' => 'active',
        ]);
        app(ResultPinService::class)->mintPins($otherBatch, 5);

        $this->as($this->admin)
            ->getJson("/api/v1/result-pins/batches/{$otherBatch->id}/reveal")
            ->assertStatus(404);

        $this->as($this->admin)
            ->getJson('/api/v1/result-pins/inventory')
            ->assertOk()
            ->assertJsonPath('available', 0);
    }

    // ==================================================================
    // Admin controls
    // ==================================================================

    public function test_enabling_the_checker_without_a_price_is_refused()
    {
        $this->as($this->admin)
            ->putJson('/api/v1/result-pins/settings', ['result_checker_enabled' => true])
            ->assertStatus(422);
    }

    public function test_an_admin_can_waive_the_fee_for_a_scholarship_student()
    {
        $this->enableChecker();
        $this->releaseResults();

        $this->as($this->admin)
            ->postJson('/api/v1/result-pins/waive', [
                'student_id' => $this->student->id,
                'term_id' => $this->term->id,
                'reason' => 'Full scholarship',
            ])
            ->assertStatus(201);

        $this->as($this->parent)
            ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}")
            ->assertOk();

        // A waiver must not eat paid stock.
        $this->assertSame(0, ResultPin::where('origin', 'batch')->count());
    }

    public function test_withdrawing_a_release_closes_access_again()
    {
        $this->enableChecker();
        $this->releaseResults();
        app(ResultPinService::class)->waive(
            $this->school->id, $this->student->id, $this->term->id, $this->admin->id, 'test'
        );

        $this->as($this->admin)
            ->postJson('/api/v1/results/release', [
                'term_id' => $this->term->id,
                'is_released' => false,
            ])
            ->assertOk();

        // A marking error found after publication has to be retractable.
        $this->as($this->parent)
            ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}")
            ->assertStatus(404);
    }

    // ==================================================================
    // Who may declare results published
    // ==================================================================

    public function test_a_school_admin_releases_results_and_opens_the_checker()
    {
        $this->enableChecker();

        $this->as($this->admin)
            ->postJson('/api/v1/results/release', ['term_id' => $this->term->id])
            ->assertOk();

        $this->assertDatabaseHas('result_releases', [
            'school_id' => $this->school->id,
            'term_id' => $this->term->id,
            'is_released' => true,
            'released_by' => $this->admin->id,
        ]);

        $this->as($this->parent)
            ->getJson("/api/v1/result-checker/{$this->student->id}/{$this->term->id}/summary")
            ->assertOk()
            ->assertJsonPath('status', 'released');
    }

    public function test_schoolpilot_staff_cannot_release_a_schools_results()
    {
        // Selling a school its software is not the same as deciding that its
        // marking is finished. Releasing stays with the school.
        $this->as($this->platformAdmin)
            ->postJson('/api/v1/results/release', ['term_id' => $this->term->id])
            ->assertStatus(403);

        $this->assertDatabaseCount('result_releases', 0);
    }

    public function test_schoolpilot_staff_cannot_read_a_schools_release_history()
    {
        $this->releaseResults();

        $this->as($this->platformAdmin)
            ->getJson('/api/v1/results/releases')
            ->assertStatus(403);
    }

    public function test_a_teacher_cannot_release_results()
    {
        $teacher = $this->makeUser('okafor@graceland.test', 'teacher', $this->school->id);

        $this->as($teacher)
            ->postJson('/api/v1/results/release', ['term_id' => $this->term->id])
            ->assertStatus(403);

        $this->assertDatabaseCount('result_releases', 0);
    }

    public function test_pin_material_never_appears_in_a_listing()
    {
        $this->grantStock(2);

        $body = $this->as($this->admin)
            ->getJson('/api/v1/result-pins/batches')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('pin_secret', $body);
        $this->assertStringNotContainsString('pin_hash', $body);
    }

    public function test_revealing_a_batch_writes_an_audit_row()
    {
        $batch = $this->grantStock(3);

        $this->as($this->admin)
            ->getJson("/api/v1/result-pins/batches/{$batch->id}/reveal")
            ->assertOk()
            ->assertJsonPath('count', 3);

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $this->school->id,
            'user_id' => $this->admin->id,
            'action' => 'result_pin.batch_revealed',
        ]);
    }

    /**
     * A correctly signed Paystack charge.success, the way the gateway sends it.
     *
     * Signed with whichever secret the callback will actually be checked
     * against: the school's own where it has connected an account, the platform
     * key where it has not.
     */
    private function postPaystackWebhook(string $reference, int $amountKobo)
    {
        config(['services.paystack.secret' => 'test_secret']);

        $connected = SchoolPaymentGateway::allTenants()
            ->where('school_id', $this->school->id)
            ->where('gateway', 'paystack')
            ->first();

        $secret = $connected?->secret_key ?: 'test_secret';

        $payload = [
            'event' => 'charge.success',
            'data' => ['reference' => $reference, 'amount' => $amountKobo],
        ];

        $body = json_encode($payload);

        return $this->call(
            'POST',
            '/api/v1/webhooks/paystack',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, $secret),
            ],
            $body
        );
    }
}
