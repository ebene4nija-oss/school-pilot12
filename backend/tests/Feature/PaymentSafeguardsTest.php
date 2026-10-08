<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\PaymentSettlement;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money at the bursary: never more than is owed, never credited twice, card
 * payments never approved by hand, and receipts to the real guardians.
 */
class PaymentSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $bursar;
    private Student $ada;
    private Invoice $invoice;
    /** @var array<int, string> phones the receipt went to */
    private array $sentTo = [];
    private string $host = 'http://crescent.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Crescent', 'slug' => 'crescent', 'subdomain' => 'crescent', 'domain' => 'crescent.schoolpilot.test']);
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31']);
        $term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-08', 'end_date' => '2026-12-18']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->bursar = $this->user('Mrs Bello', 'bursar');
        $this->ada = Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('Ada Obi', 'student')->id, 'class_id' => $class->id, 'admission_number' => 'ADA001']);
        $this->invoice = Invoice::create(['school_id' => $this->school->id, 'term_id' => $term->id, 'student_id' => $this->ada->id, 'invoice_number' => 'INV-1', 'total_amount' => 100000, 'amount_paid' => 0, 'status' => 'unpaid']);

        $test = $this;
        $this->app->instance(WhatsAppService::class, new class($test) extends WhatsAppService {
            public function __construct(private PaymentSafeguardsTest $test)
            {
            }

            public function sendPaymentReceiptNotification(string $recipientPhone, string $parentName, string $amount, string $invoiceRef): array
            {
                $this->test->recordReceipt($recipientPhone);

                return ['status' => 'sent'];
            }
        });
    }

    public function recordReceipt(string $phone): void
    {
        $this->sentTo[] = $phone;
    }

    private function user(string $name, string $role, ?string $phone = null): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'-'.uniqid().'@crescent.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role, 'phone' => $phone]);

        return $u;
    }

    private function pay(float $amount, array $extra = [])
    {
        return $this->actingAs($this->bursar, 'sanctum')->postJson("{$this->host}/finance/payments", [
            'invoice_id' => $this->invoice->id, 'amount' => $amount, 'gateway' => 'cash',
        ] + $extra);
    }

    public function test_a_payment_can_never_exceed_what_is_owed(): void
    {
        $this->pay(100001)->assertStatus(422)->assertJsonPath('message', 'That is more than the ₦100,000.00 still owed on this invoice.');
        $this->assertSame(0, Payment::count());

        $this->pay(60000)->assertOk();
        $this->pay(40000.01)->assertStatus(422);
        $this->pay(40000)->assertOk();

        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertEquals(100000, $this->invoice->fresh()->amount_paid);
        $this->pay(1)->assertStatus(422)->assertJsonPath('message', 'This invoice is already fully paid.');
    }

    public function test_the_same_cash_recorded_twice_is_caught_unless_confirmed(): void
    {
        $this->pay(20000)->assertOk();
        $this->pay(20000)->assertStatus(409);
        $this->assertSame(1, Payment::count());

        $this->pay(20000, ['confirm_duplicate' => true])->assertOk();
        $this->assertEquals(40000, $this->invoice->fresh()->amount_paid);
    }

    public function test_settling_twice_credits_the_invoice_once(): void
    {
        $payment = Payment::create(['school_id' => $this->school->id, 'invoice_id' => $this->invoice->id, 'reference' => 'PSK-1', 'amount' => 30000, 'gateway' => 'paystack', 'status' => 'pending']);
        $settlement = app(PaymentSettlement::class);

        $this->assertTrue($settlement->settle($payment));
        $this->assertFalse($settlement->settle($payment), 'A retried webhook must not credit again.');

        $this->assertEquals(30000, $this->invoice->fresh()->amount_paid);
        $this->assertSame('partial', $this->invoice->fresh()->status);
    }

    public function test_the_receipt_goes_to_the_guardians_of_record_and_never_a_placeholder(): void
    {
        $mum = $this->user('Mrs Obi', 'parent', '+2348031112222');
        $dad = $this->user('Mr Obi', 'parent', null);
        foreach ([[$mum, true], [$dad, false]] as [$parent, $primary]) {
            $g = Guardian::create(['school_id' => $this->school->id, 'user_id' => $parent->id, 'relationship' => 'parent']);
            $this->ada->guardians()->attach($g->id, ['is_primary' => $primary]);
        }

        $this->pay(10000)->assertOk();

        $this->assertSame(['+2348031112222'], $this->sentTo);
        $this->assertNotContains('+2348000000000', $this->sentTo);
    }

    public function test_no_guardian_phone_means_no_message_rather_than_a_wrong_one(): void
    {
        $this->pay(10000)->assertOk();
        $this->assertSame([], $this->sentTo);
    }

    public function test_only_pending_offline_payments_are_approved_by_hand(): void
    {
        $card = Payment::create(['school_id' => $this->school->id, 'invoice_id' => $this->invoice->id, 'reference' => 'PSK-9', 'amount' => 5000, 'gateway' => 'paystack', 'status' => 'pending']);
        $this->actingAs($this->bursar, 'sanctum')->postJson("{$this->host}/finance/payments/reconcile-bank-transfer", ['payment_id' => $card->id, 'action' => 'approve'])
            ->assertStatus(422);
        $this->assertSame('pending', $card->fresh()->status);

        $transfer = Payment::create(['school_id' => $this->school->id, 'invoice_id' => $this->invoice->id, 'reference' => 'TRF-1', 'amount' => 25000, 'gateway' => 'bank_transfer', 'status' => 'pending']);
        $this->actingAs($this->bursar, 'sanctum')->postJson("{$this->host}/finance/payments/reconcile-bank-transfer", ['payment_id' => $transfer->id, 'action' => 'approve'])
            ->assertOk();
        $this->actingAs($this->bursar, 'sanctum')->postJson("{$this->host}/finance/payments/reconcile-bank-transfer", ['payment_id' => $transfer->id, 'action' => 'approve'])
            ->assertStatus(409);
        $this->assertEquals(25000, $this->invoice->fresh()->amount_paid);

        // Rejecting money already on the invoice used to leave it there.
        $this->actingAs($this->bursar, 'sanctum')->postJson("{$this->host}/finance/payments/reconcile-bank-transfer", ['payment_id' => $transfer->id, 'action' => 'reject'])
            ->assertStatus(409);
        $this->assertSame('successful', $transfer->fresh()->status);
    }
}
