<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\IdCard;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\School;
use App\Models\Staff;
use App\Models\StaffLoan;
use App\Models\User;
use App\Services\IdCard\IdCardIssuanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Staff employment lifecycle and payroll (TASK 19, 20). */
class HrPayrollTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $admin2;
    private User $bursar;
    private Staff $chidi;
    private Staff $bello;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $this->admin = $this->user('Proprietor', 'school_admin');
        $this->admin2 = $this->user('Principal', 'school_admin');
        $this->bursar = $this->user('Bursar', 'bursar');
        $this->chidi = $this->staff('Mr Chidi', 'S001');
        $this->bello = $this->staff('Mrs Bello', 'S002');
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function staff(string $name, string $number): Staff
    {
        return Staff::create(['school_id' => $this->school->id, 'user_id' => $this->user($name, 'teacher')->id, 'staff_id' => $number, 'designation' => 'Teacher']);
    }

    private function as(User $u)
    {
        return $this->actingAs($u, 'sanctum');
    }

    private function setUpPay(): void
    {
        $housing = $this->as($this->bursar)->postJson("{$this->host}/hr/pay-components", ['name' => 'Housing', 'kind' => 'earning', 'calc' => 'percent_of_basic', 'default_value' => 20])->assertCreated()->json('component.id');
        $pension = $this->as($this->bursar)->postJson("{$this->host}/hr/pay-components", ['name' => 'Pension', 'kind' => 'deduction', 'calc' => 'percent_of_basic', 'default_value' => 8, 'statutory' => true])->assertCreated()->json('component.id');
        $paye = $this->as($this->bursar)->postJson("{$this->host}/hr/pay-components", ['name' => 'PAYE', 'kind' => 'deduction', 'calc' => 'fixed', 'default_value' => 0, 'statutory' => true])->assertCreated()->json('component.id');

        $this->as($this->bursar)->postJson("{$this->host}/hr/staff/{$this->chidi->id}/salary", [
            'basic_monthly' => 150000, 'effective_from' => '2026-01-01',
            'components' => [['component_id' => $housing, 'value' => 20], ['component_id' => $pension, 'value' => 8], ['component_id' => $paye, 'value' => 9500]],
        ])->assertCreated();
        // Mrs Bello has the legacy flat salary only.
        $this->bello->update(['salary' => 120000]);
    }

    public function test_payslips_are_computed_from_the_structure_in_force(): void
    {
        $this->setUpPay();
        $this->as($this->bursar)->postJson("{$this->host}/hr/loans", ['staff_id' => $this->chidi->id, 'kind' => 'advance', 'principal' => 30000, 'monthly_deduction' => 10000])->assertCreated();
        // A raise from November does not touch October.
        $this->as($this->bursar)->postJson("{$this->host}/hr/staff/{$this->chidi->id}/salary", ['basic_monthly' => 200000, 'effective_from' => '2026-11-01'])->assertCreated();

        $run = $this->as($this->bursar)->postJson("{$this->host}/payroll/runs", ['period' => '2026-10'])->assertOk()->json('run');
        $this->assertSame(2, $run['staff_count']);

        $slip = Payslip::where('staff_id', $this->chidi->id)->first();
        $this->assertEquals(150000, $slip->basic);
        $this->assertEquals(180000, $slip->gross);                         // + 20% housing
        $this->assertEquals(12000 + 9500 + 10000, $slip->total_deductions); // pension 8%, PAYE, advance
        $this->assertEquals(148500, $slip->net);
        $this->assertTrue(collect($slip->deductions)->firstWhere('name', 'Pension')['statutory']);

        // No individual structure: the school-wide component defaults apply to the flat salary.
        $this->assertEquals(120000 + 24000 - 9600, Payslip::where('staff_id', $this->bello->id)->value('net'));
    }

    public function test_approval_payment_and_versions(): void
    {
        $this->setUpPay();
        $runId = $this->as($this->bursar)->postJson("{$this->host}/payroll/runs", ['period' => '2026-10'])->json('run.id');

        $this->as($this->bursar)->postJson("{$this->host}/payroll/runs/{$runId}/approve")->assertForbidden();
        $this->as($this->admin)->postJson("{$this->host}/payroll/runs/{$runId}/approve")->assertOk();
        $this->as($this->bursar)->postJson("{$this->host}/payroll/runs", ['period' => '2026-10'])->assertStatus(409);

        // Staff see their payslip once approved.
        $this->assertCount(1, $this->as($this->chidi->user)->getJson("{$this->host}/me/payslips")->json('data'));

        // A correction is a new version; the approved one is kept.
        $v2 = $this->as($this->admin)->postJson("{$this->host}/payroll/runs/{$runId}/reopen", ['reason' => 'PAYE figure was wrong'])->assertOk()->json('run');
        $this->assertSame(2, $v2['version']);
        $this->assertSame('superseded', PayrollRun::find($runId)->status);
        $this->assertCount(0, $this->as($this->chidi->user)->getJson("{$this->host}/me/payslips")->json('data'), 'Draft v2 is not shown; v1 is superseded.');

        $this->as($this->admin2)->postJson("{$this->host}/payroll/runs/{$v2['id']}/approve")->assertOk();
        $this->as($this->admin2)->postJson("{$this->host}/payroll/runs/{$v2['id']}/paid", ['payment_reference' => 'GTB-BATCH-1029', 'paid_on' => now()->toDateString()])->assertOk();

        $this->assertSame('Salaries', Expense::first()->category);
        $this->assertEquals(PayrollRun::find($v2['id'])->total_net, Expense::first()->amount);
        $this->as($this->admin)->postJson("{$this->host}/payroll/runs/{$v2['id']}/reopen", ['reason' => 'too late'])->assertStatus(409);
    }

    public function test_approving_your_own_run_needs_a_recorded_reason(): void
    {
        $this->setUpPay();
        $runId = $this->as($this->admin)->postJson("{$this->host}/payroll/runs", ['period' => '2026-10'])->json('run.id');

        $this->as($this->admin)->postJson("{$this->host}/payroll/runs/{$runId}/approve")->assertStatus(409);
        $this->as($this->admin)->postJson("{$this->host}/payroll/runs/{$runId}/approve", ['self_approval_reason' => 'Only administrator at this school'])->assertOk();
        $this->assertSame('Only administrator at this school', PayrollRun::find($runId)->self_approval_reason);
    }

    public function test_loan_balances_fall_when_payroll_is_paid(): void
    {
        $this->setUpPay();
        $this->as($this->bursar)->postJson("{$this->host}/hr/loans", ['staff_id' => $this->chidi->id, 'kind' => 'loan', 'principal' => 15000, 'monthly_deduction' => 10000])->assertCreated();

        foreach (['2026-10', '2026-11'] as $period) {
            $id = $this->as($this->bursar)->postJson("{$this->host}/payroll/runs", ['period' => $period])->json('run.id');
            $this->as($this->admin)->postJson("{$this->host}/payroll/runs/{$id}/approve")->assertOk();
            $this->as($this->admin)->postJson("{$this->host}/payroll/runs/{$id}/paid", ['payment_reference' => "REF-{$period}", 'paid_on' => now()->toDateString()])->assertOk();
        }

        $loan = StaffLoan::first();
        $this->assertEquals(0, $loan->balance);
        $this->assertSame('settled', $loan->status);
        $this->assertEquals(5000, collect(Payslip::where('staff_id', $this->chidi->id)->orderByDesc('id')->first()->deductions)->firstWhere('name', 'Loan repayment')['amount'], 'The last repayment is only what is left.');
    }

    public function test_separation_ends_pay_and_the_id_card_and_lists_the_handover(): void
    {
        $this->setUpPay();
        $card = app(IdCardIssuanceService::class)->issue($this->school->id, 'staff', $this->bello->id);

        $res = $this->as($this->admin)->putJson("{$this->host}/hr/staff/{$this->bello->id}/status", ['status' => 'separated', 'separated_on' => '2026-09-30', 'separation_reason' => 'resigned'])->assertOk()->json();
        $this->assertArrayHasKey('subjects_taught', $res['handover']);
        $this->assertSame('revoked', $card->fresh()->status);
        $this->as($this->bursar)->putJson("{$this->host}/hr/staff/{$this->chidi->id}/status", ['status' => 'suspended'])->assertForbidden();

        $run = $this->as($this->bursar)->postJson("{$this->host}/payroll/runs", ['period' => '2026-10'])->json('run');
        $this->assertSame(1, $run['staff_count'], 'Not paid for a month after leaving.');

        $history = $this->as($this->admin)->getJson("{$this->host}/hr/staff/{$this->bello->id}/history")->json('status_events');
        $this->assertSame('separated', $history[0]['to']);
    }

    public function test_a_member_who_leaves_moves_to_left_members_and_cannot_sign_in(): void
    {
        $bello = $this->bello->user;
        $token = $bello->createToken('t')->plainTextToken;
        $this->as($this->admin)->putJson("{$this->host}/hr/staff/{$this->bello->id}/status", ['status' => 'separated', 'separated_on' => '2026-09-30', 'separation_reason' => 'resigned'])
            ->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'can no longer sign in'));

        $this->assertSame('left', $bello->fresh()->status);
        $this->assertSame(0, $bello->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->postJson("{$this->host}/auth/login", ['email' => $bello->email, 'password' => 'password123'])->assertForbidden();
        $this->withToken($token)->getJson("{$this->host}/me/payslips")->assertUnauthorized();

        // Current staff and left members are listed apart.
        $current = collect($this->as($this->admin)->getJson("{$this->host}/staff/directory")->json('data'))->pluck('name');
        $this->assertNotContains('Mrs Bello', $current);
        $left = $this->as($this->admin)->getJson("{$this->host}/staff/directory?group=left")->json('data');
        $this->assertSame(['Mrs Bello', '2026-09-30', 'resigned'], [$left[0]['name'], $left[0]['left_on'], $left[0]['left_reason']]);

        // Re-engaged: they can sign in again.
        $this->as($this->admin)->putJson("{$this->host}/hr/staff/{$this->bello->id}/status", ['status' => 'active'])->assertOk();
        $this->assertSame('active', $bello->fresh()->status);
        $this->app['auth']->forgetGuards();
        $this->postJson("{$this->host}/auth/login", ['email' => $bello->email, 'password' => 'password123'])->assertOk();
    }

    public function test_a_suspended_user_cannot_sign_back_in(): void
    {
        $this->chidi->user->update(['status' => 'suspended']);
        $this->postJson("{$this->host}/auth/login", ['email' => $this->chidi->user->email, 'password' => 'password123'])->assertForbidden();
    }

    public function test_leave_days_show_on_the_payslip_and_percentages_are_bounded(): void
    {
        $this->setUpPay();
        LeaveRequest::create(['school_id' => $this->school->id, 'staff_id' => $this->chidi->id, 'leave_type' => 'annual', 'start_date' => '2026-10-29', 'end_date' => '2026-11-03', 'days_requested' => 4, 'status' => 'approved']);
        $this->as($this->bursar)->postJson("{$this->host}/payroll/runs", ['period' => '2026-10']);
        $this->assertSame(3, Payslip::where('staff_id', $this->chidi->id)->value('leave_days'));

        $this->as($this->bursar)->postJson("{$this->host}/hr/pay-components", ['name' => 'Silly', 'kind' => 'deduction', 'calc' => 'percent_of_gross', 'default_value' => 150])->assertStatus(422);
    }

    public function test_the_legacy_payroll_endpoint_cannot_pay_another_schools_user(): void
    {
        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);
        $stranger = User::create(['name' => 'Stranger', 'email' => 'x@other.test', 'password' => bcrypt('password123')]);
        $stranger->userProfile()->create(['school_id' => $other->id, 'role' => 'teacher']);

        $this->as($this->admin)->postJson("{$this->host}/accounting/payroll", ['staff_id' => $stranger->id, 'month_year' => 'October 2026', 'basic_salary' => 100000])->assertStatus(422);
    }
}
