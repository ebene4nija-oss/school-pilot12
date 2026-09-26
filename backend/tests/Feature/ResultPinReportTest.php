<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\ResultPin;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who bought result PINs and what became of them.
 *
 * A school sees its own ledger down to the parent and child; SchoolPilot sees
 * per-school counts and money, and never a child's name or a PIN code.
 */
class ResultPinReportTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $other;

    private User $admin;

    private User $otherAdmin;

    private User $operator;

    private User $parent;

    private Student $student;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Graceland', 'slug' => 'graceland', 'subdomain' => 'graceland']);
        $this->other = School::create(['name' => 'Kingsway', 'slug' => 'kingsway', 'subdomain' => 'kingsway']);

        $this->admin = $this->person('Mr Bello', 'bello@graceland.test', 'school_admin', $this->school->id);
        $this->otherAdmin = $this->person('Mrs Ade', 'ade@kingsway.test', 'school_admin', $this->other->id);
        $this->operator = $this->person('Ops', 'ops@schoolpilot.test', 'super_admin', null);
        $this->parent = $this->person('Mrs Okeke', 'okeke@graceland.test', 'parent', $this->school->id);

        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-07-31']);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => '2026-09-14', 'end_date' => '2026-12-11']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2']);

        $this->student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $this->person('Chidi Okeke', 'chidi@graceland.test', 'student', $this->school->id)->id,
            'class_id' => $class->id,
            'admission_number' => 'GC/2026/010',
        ]);

        // Stock: 20 PINs for Graceland at ₦100, 5 for Kingsway at ₦80.
        $this->grant($this->school, 20, 100);
        $this->grant($this->other, 5, 80);

        // Graceland sells two over the counter; the parent uses one for Chidi.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/result-pins/counter-sale', ['amount' => 500, 'sold_to_user_id' => $this->parent->id])
            ->assertCreated();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/result-pins/counter-sale', ['amount' => 500])
            ->assertCreated();

        ResultPin::withoutGlobalScopes()->where('sold_to', $this->parent->id)->update([
            'status' => 'used',
            'student_id' => $this->student->id,
            'term_id' => $this->term->id,
            'views_used' => 2,
            'first_used_at' => now(),
        ]);
    }

    private function person(string $name, string $email, string $role, ?int $schoolId): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $schoolId, 'role' => $role]);

        return $user;
    }

    private function grant(School $school, int $quantity, float $unitPrice): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/platform/result-pins/grant', [
                'school_id' => $school->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'notes' => 'Bank transfer, test.',
            ])
            ->assertCreated();
    }

    public function test_a_school_sees_who_bought_each_pin_and_whose_result_it_opened(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/result-pins/ledger')
            ->assertOk()
            ->assertJsonCount(1, 'batches')
            ->assertJsonPath('batches.0.quantity', 20)
            ->assertJsonPath('batches.0.available', 18)
            ->assertJsonPath('batches.0.sold', 1)
            ->assertJsonPath('batches.0.used', 1)
            // Only PINs that left stock are listed.
            ->assertJsonCount(2, 'pins');

        $used = collect($res->json('pins'))->firstWhere('status', 'used');

        $this->assertSame('Mrs Okeke', $used['sold_to']);
        $this->assertSame('Chidi Okeke', $used['student']);
        $this->assertSame('GC/2026/010', $used['admission_number']);
        $this->assertSame('First Term', $used['term']);
        $this->assertSame('counter', $used['channel']);
        $this->assertEquals(500, $used['amount']);
    }

    public function test_the_ledger_filters_and_searches(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/result-pins/ledger?status=used')
            ->assertOk()
            ->assertJsonCount(1, 'pins');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/result-pins/ledger?search=chidi')
            ->assertOk()
            ->assertJsonCount(1, 'pins')
            ->assertJsonPath('pins.0.student', 'Chidi Okeke');
    }

    /** No code material in a listing — serials only. */
    public function test_the_ledger_never_carries_a_pin_code(): void
    {
        $body = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/result-pins/ledger')->assertOk()->getContent();

        foreach (ResultPin::withoutGlobalScopes()->where('school_id', $this->school->id)->get() as $pin) {
            $this->assertStringNotContainsString($pin->revealCode(), $body);
        }

        $this->assertStringNotContainsString('pin_hash', $body);
        $this->assertStringNotContainsString('pin_secret', $body);
    }

    public function test_a_school_sees_only_its_own_pins(): void
    {
        $this->actingAs($this->otherAdmin, 'sanctum')
            ->getJson('/api/v1/result-pins/ledger')
            ->assertOk()
            ->assertJsonCount(1, 'batches')
            ->assertJsonPath('batches.0.quantity', 5)
            ->assertJsonCount(0, 'pins');
    }

    public function test_the_operator_sees_every_school_with_money_and_stock(): void
    {
        $res = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/platform/result-pins/sales')
            ->assertOk()
            ->assertJsonPath('totals.schools', 2)
            ->assertJsonPath('totals.pins_bought', 25)
            ->assertJsonPath('totals.paid_to_platform', 2400) // 20×100 + 5×80
            ->assertJsonPath('totals.sold', 2)
            ->assertJsonPath('totals.used', 1);

        $graceland = collect($res->json('schools'))->firstWhere('school_id', $this->school->id);

        $this->assertSame(20, $graceland['pins_bought']);
        $this->assertEquals(2000, $graceland['paid_to_platform']);
        $this->assertSame(18, $graceland['available']);
        $this->assertSame(1, $graceland['sold']);
        $this->assertSame(1, $graceland['used']);
        $this->assertEquals(1000, $graceland['parent_revenue']);

        $this->assertCount(2, $res->json('batches'));
        $this->assertSame('Ops', collect($res->json('batches'))->firstWhere('school_id', $this->school->id)['granted_by']);
    }

    /** Counts, not children: the operator does not need to know whose result was opened. */
    public function test_the_operator_view_names_no_parent_or_child(): void
    {
        $body = $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/platform/result-pins/sales')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Chidi', $body);
        $this->assertStringNotContainsString('Okeke', $body);
        $this->assertStringNotContainsString('GC/2026/010', $body);
    }

    public function test_the_operator_can_narrow_batches_to_one_school(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/platform/result-pins/sales?school_id='.$this->other->id)
            ->assertOk()
            ->assertJsonCount(1, 'batches')
            ->assertJsonPath('batches.0.school', 'Kingsway');
    }

    public function test_each_report_is_for_its_own_audience(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/platform/result-pins/sales')->assertForbidden();
        $this->actingAs($this->parent, 'sanctum')->getJson('/api/v1/result-pins/ledger')->assertForbidden();
    }
}
