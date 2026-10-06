<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bursar's read-back lists: the payments ledger (with transfers awaiting
 * reconciliation) and the accounts screen's expenses, vendors, petty cash and
 * budgets, which could previously be written but never read.
 */
class BursarLedgerTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $bursar;
    private Term $term;
    private Student $ada;
    private Invoice $invoice;
    private string $host = 'http://crescent.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Crescent', 'slug' => 'crescent', 'subdomain' => 'crescent', 'domain' => 'crescent.schoolpilot.test']);
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31']);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-08', 'end_date' => '2026-12-18']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->bursar = $this->user('Mrs Bello', 'bursar');
        $this->ada = Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('Ada Obi', 'student')->id, 'class_id' => $class->id, 'admission_number' => 'ADA001']);
        $this->invoice = Invoice::create(['school_id' => $this->school->id, 'term_id' => $this->term->id, 'student_id' => $this->ada->id, 'invoice_number' => 'INV-1', 'total_amount' => 100000, 'amount_paid' => 0, 'status' => 'unpaid']);
    }

    private function user(string $name, string $role, ?School $school = null): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'-'.uniqid().'@crescent.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => ($school ?? $this->school)->id, 'role' => $role]);

        return $u;
    }

    private function as(User $u)
    {
        return $this->actingAs($u, 'sanctum');
    }

    public function test_the_ledger_lists_payments_and_the_transfers_waiting_to_be_reconciled(): void
    {
        Payment::create(['school_id' => $this->school->id, 'invoice_id' => $this->invoice->id, 'reference' => 'CASH-1', 'amount' => 30000, 'gateway' => 'cash', 'status' => 'successful', 'paid_at' => now()]);
        $waiting = Payment::create(['school_id' => $this->school->id, 'invoice_id' => $this->invoice->id, 'reference' => 'TRF-9', 'amount' => 20000, 'gateway' => 'bank_transfer', 'status' => 'pending']);

        $all = $this->as($this->bursar)->getJson("{$this->host}/finance/payments")->assertOk()->json();
        $this->assertCount(2, $all['data']);
        $this->assertEquals(30000, $all['total_received'], 'Only confirmed money counts as received.');
        $this->assertSame('Ada Obi', $all['data'][0]['student']);

        $pending = $this->as($this->bursar)->getJson("{$this->host}/finance/payments?status=pending&gateway=bank_transfer")->json('data');
        $this->assertSame([$waiting->id], array_column($pending, 'payment_id'));

        $this->assertCount(2, $this->as($this->bursar)->getJson("{$this->host}/finance/payments?q=ADA001")->json('data'));
        $this->assertCount(0, $this->as($this->bursar)->getJson("{$this->host}/finance/payments?q=nobody")->json('data'));
    }

    public function test_the_ledger_is_staff_only_and_never_shows_another_school(): void
    {
        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other', 'domain' => 'other.schoolpilot.test']);
        $otherInvoice = Invoice::create(['school_id' => $other->id, 'term_id' => $this->term->id, 'student_id' => $this->ada->id, 'invoice_number' => 'X-1', 'total_amount' => 5, 'amount_paid' => 0, 'status' => 'unpaid']);
        Payment::create(['school_id' => $other->id, 'invoice_id' => $otherInvoice->id, 'reference' => 'OTHER', 'amount' => 5, 'gateway' => 'cash', 'status' => 'successful', 'paid_at' => now()]);

        $this->assertSame([], $this->as($this->bursar)->getJson("{$this->host}/finance/payments")->json('data'));
        $this->as($this->user('Mr Obi', 'parent'))->getJson("{$this->host}/finance/payments")->assertForbidden();
        $this->as($this->user('Mr Eze', 'teacher'))->getJson("{$this->host}/accounting/expenses")->assertForbidden();
    }

    public function test_what_the_accounts_screen_records_can_be_read_back(): void
    {
        $vendor = $this->as($this->bursar)->postJson("{$this->host}/accounting/vendors", ['name' => 'Lagos Diesel Ltd'])->assertSuccessful()->json('vendor.id');
        $this->as($this->bursar)->postJson("{$this->host}/accounting/expenses", ['vendor_id' => $vendor, 'category' => 'Fuel', 'title' => 'Generator diesel', 'amount' => 45000, 'expense_date' => '2026-10-02', 'payment_method' => 'cash'])->assertSuccessful();
        $this->as($this->bursar)->postJson("{$this->host}/accounting/expenses", ['category' => 'Repairs', 'title' => 'Roof', 'amount' => 80000, 'expense_date' => '2026-08-01', 'payment_method' => 'bank_transfer'])->assertSuccessful();
        $this->as($this->bursar)->postJson("{$this->host}/accounting/petty-cash", ['type' => 'replenishment', 'amount' => 50000, 'purpose' => 'Float', 'entry_date' => '2026-10-01'])->assertSuccessful();
        $this->as($this->bursar)->postJson("{$this->host}/accounting/petty-cash", ['type' => 'disbursement', 'amount' => 12500, 'purpose' => 'Chalk', 'entry_date' => '2026-10-02'])->assertSuccessful();
        $this->as($this->bursar)->postJson("{$this->host}/accounting/budget", ['term_id' => $this->term->id, 'category' => 'Fuel', 'allocated_amount' => 100000, 'fiscal_year' => '2026/2027'])->assertSuccessful();

        $this->assertSame('Lagos Diesel Ltd', $this->as($this->bursar)->getJson("{$this->host}/accounting/vendors")->json('data.0.name'));

        $expenses = $this->as($this->bursar)->getJson("{$this->host}/accounting/expenses?from=2026-09-01")->assertOk()->json();
        $this->assertCount(1, $expenses['data']);
        $this->assertSame('Lagos Diesel Ltd', $expenses['data'][0]['vendor']['name']);
        $this->assertEquals(45000, $expenses['total']);
        $this->assertSame(['Fuel', 'Repairs'], $expenses['categories']);

        $petty = $this->as($this->bursar)->getJson("{$this->host}/accounting/petty-cash")->json();
        $this->assertEquals(37500, $petty['balance']);
        $this->assertSame('Mrs Bello', $petty['data'][0]['custodian']);

        $budget = $this->as($this->bursar)->getJson("{$this->host}/accounting/budget")->json('data.0');
        $this->assertEquals(45000, $budget['spent'], 'Only this term\'s fuel, not August\'s roof.');
        $this->assertEquals(55000, $budget['remaining']);
    }

    public function test_hostel_charges_name_the_student_for_finance(): void
    {
        \App\Models\HostelCharge::create(['school_id' => $this->school->id, 'student_id' => $this->ada->id, 'term_id' => $this->term->id, 'charge_type' => 'boarding', 'title' => 'Boarding fee', 'amount' => 150000, 'dedupe_key' => 'ada-boarding-1', 'status' => 'pending']);

        $row = $this->as($this->bursar)->getJson("{$this->host}/hostel/charges")->assertOk()->json('data.0');
        $this->assertSame('Ada Obi', $row['student_name']);
        $this->assertSame('Boarding fee', $row['title']);
    }

    public function test_the_income_report_can_cover_a_period(): void
    {
        Payment::create(['school_id' => $this->school->id, 'invoice_id' => $this->invoice->id, 'reference' => 'A', 'amount' => 30000, 'gateway' => 'cash', 'status' => 'successful', 'paid_at' => '2026-10-01 10:00:00']);
        Payment::create(['school_id' => $this->school->id, 'invoice_id' => $this->invoice->id, 'reference' => 'B', 'amount' => 70000, 'gateway' => 'cash', 'status' => 'successful', 'paid_at' => '2026-07-01 10:00:00']);
        Expense::create(['school_id' => $this->school->id, 'category' => 'Fuel', 'title' => 'x', 'amount' => 10000, 'expense_date' => '2026-10-02', 'payment_method' => 'cash', 'status' => 'paid']);

        $all = $this->as($this->bursar)->getJson("{$this->host}/accounting/income-report")->assertOk()->json('financial_statement');
        $this->assertEquals(100000, $all['total_fee_income']);

        $october = $this->as($this->bursar)->getJson("{$this->host}/accounting/income-report?from=2026-10-01&to=2026-10-31")->json('financial_statement');
        $this->assertEquals(30000, $october['total_fee_income']);
        $this->assertEquals(10000, $october['total_operating_expenses']);
    }
}
