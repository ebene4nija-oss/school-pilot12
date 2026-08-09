<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §7.12 fee-structure builder and automated invoicing.
 *
 * Before this, an admin could create a fee structure and nothing else: no read,
 * no edit, no delete, and — the real gap — nothing anywhere in the codebase
 * turned a fee structure into an invoice. Fees were defined and no family was
 * ever billed.
 */
class FeeStructureTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private Term $term;
    private SchoolClass $jss1;
    private SchoolClass $ss1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Greenfield Academy',
            'slug' => 'greenfield',
            'subdomain' => 'greenfield',
            'domain' => 'greenfield.schoolpilot.test',
        ]);

        $this->admin = $this->user('Bursar', 'bursar@greenfield.test', 'school_admin');

        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->ss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'SS 1']);

        $session = AcademicSession::create([
            'school_id' => $this->school->id, 'name' => '2025/2026',
            'start_date' => now()->subMonths(6), 'end_date' => now()->addMonths(6),
        ]);

        $this->term = Term::create([
            'school_id' => $this->school->id, 'session_id' => $session->id,
            'name' => 'First Term', 'start_date' => now()->subMonths(3),
            'end_date' => now()->addMonth(), 'is_current' => true,
        ]);
    }

    private function user(string $name, string $email, string $role, ?School $school = null): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password123')]);
        $user->userProfile()->create(['school_id' => ($school ?? $this->school)->id, 'role' => $role]);

        return $user;
    }

    private function student(string $name, ?SchoolClass $class, string $status = 'active'): Student
    {
        $user = $this->user($name, strtolower(str_replace(' ', '', $name)) . '@greenfield.test', 'student');

        return Student::create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'class_id' => $class?->id,
            'admission_number' => 'ADM' . $user->id,
            'status' => $status,
        ]);
    }

    private function fee(string $title, float $amount, ?SchoolClass $class = null, bool $mandatory = true): FeeStructure
    {
        return FeeStructure::create([
            'school_id' => $this->school->id,
            'term_id' => $this->term->id,
            'class_id' => $class?->id,
            'title' => $title,
            'amount' => $amount,
            'is_mandatory' => $mandatory,
        ]);
    }

    private function url(string $path): string
    {
        return 'http://greenfield.schoolpilot.test/api/v1' . $path;
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin, 'sanctum');
    }

    private function generate(array $payload = [])
    {
        return $this->asAdmin()->postJson(
            $this->url('/finance/invoices/generate'),
            $payload + ['term_id' => $this->term->id]
        );
    }

    // ---------------------------------------------------------------- CRUD

    public function test_admin_can_create_a_fee_structure()
    {
        $this->asAdmin()
            ->postJson($this->url('/finance/fee-structure'), [
                'term_id' => $this->term->id,
                'class_id' => $this->jss1->id,
                'title' => 'JSS 1 Tuition',
                'amount' => 45000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('fee_structure.title', 'JSS 1 Tuition');

        $this->assertDatabaseHas('fee_structures', [
            'school_id' => $this->school->id,
            'title' => 'JSS 1 Tuition',
            'amount' => 45000,
        ]);
    }

    public function test_admin_can_list_fee_structures_with_per_class_totals()
    {
        $this->fee('Tuition', 45000, $this->jss1);
        $this->fee('Lab / ICT', 5000, $this->jss1);
        $this->fee('Development Levy', 3000); // school-wide
        $this->fee('Excursion', 12000, $this->jss1, mandatory: false);

        $response = $this->asAdmin()
            ->getJson($this->url('/finance/fee-structure'))
            ->assertStatus(200)
            ->assertJsonPath('currency', 'NGN');

        $this->assertCount(4, $response->json('data'));

        $byClass = collect($response->json('by_class'))->keyBy('class');

        // 45,000 + 5,000 + 3,000 school-wide. The optional excursion is not
        // part of what a family must pay.
        $this->assertEquals(53000.0, $byClass['JSS 1']['total_payable']);
        // SS 1 has no fees of its own, so only the school-wide levy.
        $this->assertEquals(3000.0, $byClass['SS 1']['total_payable']);

        // The builder form needs these and no other endpoint lists them.
        $this->assertNotEmpty($response->json('terms'));
        $this->assertNotEmpty($response->json('classes'));
    }

    /** The fat-finger fix: ₦450,000 instead of ₦45,000 used to need SQL. */
    public function test_admin_can_correct_a_fee_amount()
    {
        $fee = $this->fee('Tuition', 450000, $this->jss1);

        $this->asAdmin()
            ->putJson($this->url("/finance/fee-structure/{$fee->id}"), [
                'term_id' => $this->term->id,
                'class_id' => $this->jss1->id,
                'title' => 'Tuition',
                'amount' => 45000,
            ])
            ->assertStatus(200);

        $this->assertEquals(45000.0, (float) $fee->fresh()->amount);
    }

    public function test_admin_can_delete_a_fee_structure()
    {
        $fee = $this->fee('Retired Bus Fee', 8000);

        $this->asAdmin()
            ->deleteJson($this->url("/finance/fee-structure/{$fee->id}"))
            ->assertStatus(200)
            ->assertJsonPath('invoiced_lines_retained', 0);

        $this->assertDatabaseMissing('fee_structures', ['id' => $fee->id]);
    }

    /**
     * Deleting a fee must not erase bills already issued from it — that is a
     * family's payment history, not a stale config row.
     */
    public function test_deleting_a_fee_keeps_the_lines_already_billed()
    {
        $this->student('Billed Child', $this->jss1);
        $fee = $this->fee('Tuition', 45000, $this->jss1);

        $this->generate()->assertStatus(200);
        $this->assertDatabaseCount('invoice_items', 1);

        $this->asAdmin()
            ->deleteJson($this->url("/finance/fee-structure/{$fee->id}"))
            ->assertStatus(200)
            ->assertJsonPath('invoiced_lines_retained', 1);

        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertNull(InvoiceItem::first()->fee_structure_id);
        // The bill itself is untouched.
        $this->assertEquals(45000.0, (float) Invoice::first()->total_amount);
    }

    // ------------------------------------------------------------ Invoicing

    public function test_generation_bills_class_fees_plus_school_wide_fees()
    {
        $junior = $this->student('Junior Child', $this->jss1);
        $senior = $this->student('Senior Child', $this->ss1);

        $this->fee('Tuition', 45000, $this->jss1);
        $this->fee('Tuition', 55000, $this->ss1);
        $this->fee('Development Levy', 3000);

        $this->generate(['due_date' => now()->addMonth()->toDateString()])
            ->assertStatus(200)
            ->assertJsonPath('summary.invoices_created', 2);

        $juniorInvoice = Invoice::where('student_id', $junior->id)->first();
        $seniorInvoice = Invoice::where('student_id', $senior->id)->first();

        $this->assertEquals(48000.0, (float) $juniorInvoice->total_amount);
        $this->assertEquals(58000.0, (float) $seniorInvoice->total_amount);

        // Two lines each: own class fee + the school-wide levy.
        $this->assertSame(2, $juniorInvoice->items()->count());
        $this->assertSame('unpaid', $juniorInvoice->status);
        $this->assertNotNull($juniorInvoice->due_date);
    }

    /** A bursar will press Generate twice. It must not double-charge. */
    public function test_generation_is_idempotent()
    {
        $student = $this->student('Only Child', $this->jss1);
        $this->fee('Tuition', 45000, $this->jss1);

        $this->generate()->assertStatus(200);
        $this->generate()
            ->assertStatus(200)
            ->assertJsonPath('summary.invoices_created', 0)
            ->assertJsonPath('summary.lines_added', 0);

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertEquals(45000.0, (float) Invoice::where('student_id', $student->id)->first()->total_amount);
    }

    /** A levy added mid-term lands on the invoice the student already has. */
    public function test_a_fee_added_after_invoicing_is_appended_not_duplicated()
    {
        $student = $this->student('Mid Term Child', $this->jss1);
        $this->fee('Tuition', 45000, $this->jss1);

        $this->generate()->assertStatus(200);

        $this->fee('ICT Levy', 5000, $this->jss1);

        $this->generate()
            ->assertStatus(200)
            ->assertJsonPath('summary.invoices_created', 0)
            ->assertJsonPath('summary.invoices_updated', 1)
            ->assertJsonPath('summary.lines_added', 1);

        $invoice = Invoice::where('student_id', $student->id)->first();

        $this->assertDatabaseCount('invoices', 1);
        $this->assertEquals(50000.0, (float) $invoice->total_amount);
        $this->assertSame(2, $invoice->items()->count());
    }

    /** A student admitted in week two gets billed on the next run. */
    public function test_a_late_admission_is_picked_up_by_a_re_run()
    {
        $this->student('Early Child', $this->jss1);
        $this->fee('Tuition', 45000, $this->jss1);

        $this->generate()->assertStatus(200);

        $late = $this->student('Late Child', $this->jss1);

        $this->generate()
            ->assertStatus(200)
            ->assertJsonPath('summary.invoices_created', 1);

        $this->assertDatabaseCount('invoices', 2);
        $this->assertEquals(45000.0, (float) Invoice::where('student_id', $late->id)->first()->total_amount);
    }

    /** Optional fees are opt-in purchases, not debts. */
    public function test_optional_fees_are_not_auto_billed()
    {
        $this->student('Day Child', $this->jss1);
        $this->fee('Tuition', 45000, $this->jss1);
        $this->fee('Ski Trip', 250000, $this->jss1, mandatory: false);

        $this->generate()->assertStatus(200);

        $this->assertEquals(45000.0, (float) Invoice::first()->total_amount);
        $this->assertDatabaseCount('invoice_items', 1);
    }

    public function test_graduated_and_transferred_students_are_not_billed()
    {
        $this->student('Active Child', $this->jss1);
        $this->student('Graduated Child', $this->jss1, status: 'graduated');
        $this->student('Transferred Child', $this->jss1, status: 'transferred');

        $this->fee('Tuition', 45000, $this->jss1);

        $this->generate()
            ->assertStatus(200)
            ->assertJsonPath('summary.invoices_created', 1);

        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_generation_can_be_limited_to_one_class()
    {
        $this->student('Junior Child', $this->jss1);
        $this->student('Senior Child', $this->ss1);

        $this->fee('Development Levy', 3000);

        $this->generate(['class_id' => $this->jss1->id])
            ->assertStatus(200)
            ->assertJsonPath('summary.invoices_created', 1);

        $this->assertDatabaseCount('invoices', 1);
    }

    /**
     * The regression this design exists to prevent.
     *
     * Discounts are written straight onto `total_amount`. If a re-run recomputed
     * the total from its line items, every bursary the school had granted would
     * quietly come back — discovered by the parent at the school gate.
     */
    public function test_a_re_run_does_not_reinstate_a_granted_discount()
    {
        $student = $this->student('Bursary Child', $this->jss1);
        $this->fee('Tuition', 100000, $this->jss1);

        $this->generate()->assertStatus(200);
        $invoice = Invoice::where('student_id', $student->id)->first();

        $scholarship = Scholarship::create([
            'school_id' => $this->school->id,
            'name' => 'Staff child 25%', 'type' => 'percentage', 'value' => 25,
        ]);

        $this->asAdmin()
            ->postJson($this->url("/finance/invoices/{$invoice->id}/apply-discount"), [
                'scholarship_id' => $scholarship->id,
            ])->assertStatus(200);

        $this->assertEquals(75000.0, (float) $invoice->fresh()->total_amount);

        $this->generate()->assertStatus(200);

        $this->assertEquals(75000.0, (float) $invoice->fresh()->total_amount);
    }

    /** A generated invoice must reach the bursar's debtor list. */
    public function test_generated_invoices_appear_on_the_defaulter_dashboard()
    {
        $this->student('Owing Child', $this->jss1);
        $this->fee('Tuition', 45000, $this->jss1);

        $this->generate(['due_date' => now()->subDays(40)->toDateString()])->assertStatus(200);

        $response = $this->asAdmin()
            ->getJson($this->url('/finance/defaulters'))
            ->assertStatus(200);

        $this->assertEquals(45000.0, $response->json('summary.total_outstanding'));
        $this->assertSame('Owing Child', $response->json('defaulters.0.student_name'));
        $this->assertSame('31-60_days', $response->json('defaulters.0.ageing_bucket'));
    }

    public function test_generation_reports_when_no_fees_are_defined()
    {
        $this->student('Unbilled Child', $this->jss1);

        $this->generate()
            ->assertStatus(200)
            ->assertJsonPath('summary.invoices_created', 0);

        $this->assertDatabaseCount('invoices', 0);
    }

    // ------------------------------------------------- Authorization & tenancy

    public function test_a_teacher_cannot_touch_fee_structures_or_raise_invoices()
    {
        $teacher = $this->user('Teacher', 'teacher@greenfield.test', 'teacher');
        $fee = $this->fee('Tuition', 45000, $this->jss1);

        $this->actingAs($teacher, 'sanctum')
            ->getJson($this->url('/finance/fee-structure'))->assertStatus(403);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson($this->url("/finance/fee-structure/{$fee->id}"))->assertStatus(403);

        $this->actingAs($teacher, 'sanctum')
            ->postJson($this->url('/finance/invoices/generate'), ['term_id' => $this->term->id])
            ->assertStatus(403);
    }

    public function test_a_parent_cannot_raise_invoices()
    {
        $parent = $this->user('Parent', 'parent@greenfield.test', 'parent');

        $this->actingAs($parent, 'sanctum')
            ->postJson($this->url('/finance/invoices/generate'), ['term_id' => $this->term->id])
            ->assertStatus(403);
    }

    /**
     * The old `exists:terms,id` rule was unscoped, so a school could hang a fee
     * off another tenant's term.
     */
    public function test_a_fee_cannot_be_attached_to_another_schools_term()
    {
        $other = School::create([
            'name' => 'Rival School', 'slug' => 'rival',
            'subdomain' => 'rival', 'domain' => 'rival.schoolpilot.test',
        ]);

        $otherSession = AcademicSession::create([
            'school_id' => $other->id, 'name' => '2025/2026',
            'start_date' => now()->subMonths(6), 'end_date' => now()->addMonths(6),
        ]);

        $otherTerm = Term::create([
            'school_id' => $other->id, 'session_id' => $otherSession->id,
            'name' => 'First Term', 'start_date' => now()->subMonths(3), 'end_date' => now()->addMonth(),
        ]);

        $this->asAdmin()
            ->postJson($this->url('/finance/fee-structure'), [
                'term_id' => $otherTerm->id,
                'title' => 'Cross-tenant fee',
                'amount' => 1000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('term_id');
    }

    public function test_an_admin_cannot_edit_another_schools_fee_structure()
    {
        $other = School::create([
            'name' => 'Rival School', 'slug' => 'rival',
            'subdomain' => 'rival', 'domain' => 'rival.schoolpilot.test',
        ]);

        $otherSession = AcademicSession::create([
            'school_id' => $other->id, 'name' => '2025/2026',
            'start_date' => now()->subMonths(6), 'end_date' => now()->addMonths(6),
        ]);

        $otherTerm = Term::create([
            'school_id' => $other->id, 'session_id' => $otherSession->id,
            'name' => 'First Term', 'start_date' => now()->subMonths(3), 'end_date' => now()->addMonth(),
        ]);

        $foreignFee = FeeStructure::create([
            'school_id' => $other->id, 'term_id' => $otherTerm->id,
            'title' => 'Their tuition', 'amount' => 90000, 'is_mandatory' => true,
        ]);

        $this->asAdmin()
            ->deleteJson($this->url("/finance/fee-structure/{$foreignFee->id}"))
            ->assertStatus(404);

        $this->assertDatabaseHas('fee_structures', ['id' => $foreignFee->id]);
    }

    public function test_amount_must_be_present_and_non_negative()
    {
        $this->asAdmin()
            ->postJson($this->url('/finance/fee-structure'), [
                'term_id' => $this->term->id,
                'title' => 'Bad fee',
                'amount' => -500,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }
}
