<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\PaymentInstallment;
use App\Models\Scholarship;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §7.12 fee collection: the defaulter/debtor dashboard, instalment plans and
 * discounts. None of it existed — the module could take money but not chase it.
 */
class FeeCollectionTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private Term $term;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Crescent School',
            'slug' => 'crescent',
            'subdomain' => 'crescent',
            'domain' => 'crescent.schoolpilot.test',
        ]);

        $this->admin = $this->user('Bursar', 'bursar@crescent.test', 'school_admin');
        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);

        $session = AcademicSession::create([
            'school_id' => $this->school->id, 'name' => '2025/2026',
            'start_date' => now()->subMonths(6), 'end_date' => now()->addMonths(6),
        ]);

        $this->term = Term::create([
            'school_id' => $this->school->id, 'session_id' => $session->id,
            'name' => 'First Term', 'start_date' => now()->subMonths(3), 'end_date' => now()->addMonth(),
        ]);
    }

    private function user(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function student(string $name, string $admission): Student
    {
        $user = $this->user($name, strtolower(str_replace(' ', '', $name)) . '@crescent.test', 'student');

        return Student::create([
            'school_id' => $this->school->id, 'user_id' => $user->id,
            'class_id' => $this->class->id, 'admission_number' => $admission,
        ]);
    }

    private function invoice(Student $student, float $total, float $paid, ?int $daysOverdue = null, string $number = 'INV'): Invoice
    {
        return Invoice::create([
            'school_id' => $this->school->id,
            'term_id' => $this->term->id,
            'student_id' => $student->id,
            'invoice_number' => $number . '-' . uniqid(),
            'total_amount' => $total,
            'amount_paid' => $paid,
            'status' => $paid <= 0 ? 'unpaid' : ($paid >= $total ? 'paid' : 'partial'),
            'due_date' => $daysOverdue !== null ? now()->subDays($daysOverdue) : now()->addMonth(),
        ]);
    }

    private function url(string $path): string
    {
        return 'http://crescent.schoolpilot.test/api/v1' . $path;
    }

    public function test_defaulter_dashboard_lists_only_students_who_owe()
    {
        $owing = $this->student('Owing Child', 'ADM400');
        $settled = $this->student('Settled Child', 'ADM401');

        $this->invoice($owing, 100000, 40000);
        $this->invoice($settled, 100000, 100000);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/finance/defaulters'))
            ->assertStatus(200);

        $names = collect($response->json('defaulters'))->pluck('student_name');

        $this->assertTrue($names->contains('Owing Child'));
        $this->assertFalse($names->contains('Settled Child'));
        $this->assertEquals(60000.0, $response->json('summary.total_outstanding'));
        $this->assertSame('NGN', $response->json('summary.currency'));
    }

    public function test_defaulters_are_aged_into_buckets()
    {
        $recent = $this->student('Recent Debtor', 'ADM410');
        $old = $this->student('Old Debtor', 'ADM411');

        $this->invoice($recent, 50000, 0, daysOverdue: 10);
        $this->invoice($old, 50000, 0, daysOverdue: 120);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/finance/defaulters'))
            ->assertStatus(200);

        $buckets = collect($response->json('defaulters'))->pluck('ageing_bucket', 'student_name');

        $this->assertSame('1-30_days', $buckets['Recent Debtor']);
        $this->assertSame('over_90_days', $buckets['Old Debtor']);

        // Oldest debt first — those are the ones that never get paid.
        $this->assertSame('Old Debtor', $response->json('defaulters.0.student_name'));
    }

    public function test_installment_plan_must_add_up_to_the_outstanding_balance()
    {
        $student = $this->student('Plan Child', 'ADM420');
        $invoice = $this->invoice($student, 90000, 0);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url("/finance/invoices/{$invoice->id}/installment-plan"), [
                'installments' => [
                    ['amount' => 30000, 'due_date' => now()->addMonth()->toDateString()],
                    ['amount' => 30000, 'due_date' => now()->addMonths(2)->toDateString()],
                ],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('payment_installments', 0);
    }

    public function test_installment_plan_is_created_and_overdue_tranches_flagged()
    {
        $student = $this->student('Plan Child Two', 'ADM421');
        $invoice = $this->invoice($student, 90000, 0);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url("/finance/invoices/{$invoice->id}/installment-plan"), [
                'installments' => [
                    ['amount' => 30000, 'due_date' => now()->subWeek()->toDateString()],
                    ['amount' => 30000, 'due_date' => now()->addMonth()->toDateString()],
                    ['amount' => 30000, 'due_date' => now()->addMonths(2)->toDateString()],
                ],
            ])
            ->assertStatus(201);

        $this->assertDatabaseCount('payment_installments', 3);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url("/finance/invoices/{$invoice->id}/installment-plan"))
            ->assertStatus(200)
            ->assertJsonPath('overdue_count', 1);
    }

    /** A renegotiated plan replaces the old one rather than stacking. */
    public function test_new_plan_supersedes_the_previous_unpaid_one()
    {
        $student = $this->student('Renegotiator', 'ADM422');
        $invoice = $this->invoice($student, 60000, 0);

        $plan = fn (array $rows) => $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url("/finance/invoices/{$invoice->id}/installment-plan"), ['installments' => $rows]);

        $plan([
            ['amount' => 30000, 'due_date' => now()->addMonth()->toDateString()],
            ['amount' => 30000, 'due_date' => now()->addMonths(2)->toDateString()],
        ])->assertStatus(201);

        $plan([
            ['amount' => 20000, 'due_date' => now()->addMonth()->toDateString()],
            ['amount' => 20000, 'due_date' => now()->addMonths(2)->toDateString()],
            ['amount' => 20000, 'due_date' => now()->addMonths(3)->toDateString()],
        ])->assertStatus(201);

        $this->assertSame(3, PaymentInstallment::where('invoice_id', $invoice->id)->count());
    }

    public function test_percentage_discount_reduces_the_invoice()
    {
        $student = $this->student('Discount Child', 'ADM430');
        $invoice = $this->invoice($student, 100000, 0);

        $scholarship = Scholarship::create([
            'school_id' => $this->school->id,
            'name' => 'Staff child 25%',
            'type' => 'percentage',
            'value' => 25,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url("/finance/invoices/{$invoice->id}/apply-discount"), [
                'scholarship_id' => $scholarship->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('discount_applied', fn ($v) => (float) $v === 25000.0);

        $this->assertEquals(75000.0, (float) $invoice->fresh()->total_amount);
    }

    /** A bursary larger than the fee must not leave the school owing money. */
    public function test_fixed_discount_is_capped_at_the_invoice_total()
    {
        $student = $this->student('Full Bursary', 'ADM431');
        $invoice = $this->invoice($student, 50000, 0);

        $scholarship = Scholarship::create([
            'school_id' => $this->school->id,
            'name' => 'Full bursary',
            'type' => 'fixed',
            'value' => 80000,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url("/finance/invoices/{$invoice->id}/apply-discount"), [
                'scholarship_id' => $scholarship->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('discount_applied', fn ($v) => (float) $v === 50000.0);

        $fresh = $invoice->fresh();
        $this->assertEquals(0.0, (float) $fresh->total_amount);
        $this->assertSame('paid', $fresh->status);
    }

    /** A fully discounted invoice must drop off the defaulter list. */
    public function test_discounted_invoice_leaves_the_defaulter_list()
    {
        $student = $this->student('Cleared Child', 'ADM432');
        $invoice = $this->invoice($student, 50000, 0);

        $scholarship = Scholarship::create([
            'school_id' => $this->school->id,
            'name' => 'Full bursary', 'type' => 'percentage', 'value' => 100,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url("/finance/invoices/{$invoice->id}/apply-discount"), [
                'scholarship_id' => $scholarship->id,
            ])->assertStatus(200);

        $names = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/finance/defaulters'))
            ->json('defaulters'))->pluck('student_name');

        $this->assertFalse($names->contains('Cleared Child'));
    }

    public function test_teacher_cannot_read_the_defaulter_dashboard()
    {
        $teacher = $this->user('Teacher', 'teacher@crescent.test', 'teacher');

        $this->actingAs($teacher, 'sanctum')
            ->getJson($this->url('/finance/defaulters'))
            ->assertStatus(403);
    }
}
