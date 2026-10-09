<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\Invoice;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who has paid and who owes, by section, class and arm — for the admin and
 * the bursar, and nobody else.
 */
class FeeStatusReportTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Term $term;
    private SchoolClass $jss2;
    private SchoolClass $pry3;
    private Arm $jss2a;
    private Arm $jss2b;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create(['name' => 'Crescent School', 'slug' => 'crescent', 'subdomain' => 'crescent', 'domain' => 'crescent.schoolpilot.test']);
        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonths(2), 'end_date' => now()->addMonths(9)]);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonths(2)]);

        $this->jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2', 'level_category' => 'junior_secondary', 'order_index' => 8]);
        $this->pry3 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'Primary 3', 'level_category' => 'primary', 'order_index' => 3]);
        $this->jss2a = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->jss2->id, 'name' => 'A']);
        $this->jss2b = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->jss2->id, 'name' => 'B']);
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => ucfirst($role).' '.++$this->n, 'email' => "{$role}{$this->n}@crescent.test", 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function pupil(SchoolClass $class, ?Arm $arm, ?float $billed = null, float $paid = 0): Student
    {
        $student = Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('student')->id, 'class_id' => $class->id, 'arm_id' => $arm?->id, 'admission_number' => 'CR'.$this->n, 'status' => 'active']);

        if ($billed !== null) {
            Invoice::create([
                'school_id' => $this->school->id, 'term_id' => $this->term->id, 'student_id' => $student->id,
                'invoice_number' => 'INV-'.$this->n, 'total_amount' => $billed, 'amount_paid' => $paid,
                'status' => $paid >= $billed ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
            ]);
        }

        return $student;
    }

    private function report(User $as, string $qs = '')
    {
        return $this->actingAs($as, 'sanctum')->getJson("http://crescent.schoolpilot.test/api/v1/finance/fee-status?term_id={$this->term->id}{$qs}");
    }

    public function test_every_student_has_one_status_and_totals_roll_up_by_section_class_and_arm()
    {
        $this->pupil($this->jss2, $this->jss2a, 100000, 100000);  // paid
        $this->pupil($this->jss2, $this->jss2a, 100000, 40000);   // part paid
        $this->pupil($this->jss2, $this->jss2b, 100000);          // owing
        $this->pupil($this->pry3, null);                           // not invoiced

        $r = $this->report($this->user('bursar'))->assertOk();

        $r->assertJsonPath('totals.billed', 300000)
            ->assertJsonPath('totals.paid', 140000)
            ->assertJsonPath('totals.outstanding', 160000)
            ->assertJsonPath('totals.counts', ['paid' => 1, 'part_paid' => 1, 'owing' => 1, 'not_invoiced' => 1]);

        // Sections in their natural order, classes in teaching order.
        $this->assertSame(['primary', 'secondary'], collect($r->json('sections'))->pluck('section')->all());
        $this->assertSame(['Primary 3', 'JSS 2'], collect($r->json('classes'))->pluck('class')->all());

        $arms = collect($r->json('classes.1.arms'))->keyBy('arm');
        $this->assertSame(140000, (int) $arms['A']['paid']);
        $this->assertSame(1, $arms['B']['counts']['owing']);
    }

    public function test_it_narrows_to_a_section_an_arm_or_a_status()
    {
        $this->pupil($this->jss2, $this->jss2a, 50000, 50000);
        $this->pupil($this->jss2, $this->jss2b, 50000);
        $this->pupil($this->pry3, null, 30000);

        $admin = $this->user('school_admin');

        $this->report($admin, '&section=primary')->assertOk()->assertJsonPath('totals.students', 1);
        $this->report($admin, "&arm_id={$this->jss2b->id}")->assertOk()->assertJsonPath('totals.outstanding', 50000);

        $owing = $this->report($admin, '&status=owing')->assertOk()->json('students');
        $this->assertCount(2, $owing);
        $this->assertSame(['owing'], array_values(array_unique(array_column($owing, 'status'))));
    }

    public function test_the_defaulter_list_filters_by_arm_and_section()
    {
        $this->pupil($this->jss2, $this->jss2a, 50000);
        $this->pupil($this->jss2, $this->jss2b, 50000);
        $this->pupil($this->pry3, null, 30000);

        $bursar = $this->user('bursar');
        $get = fn (string $qs) => $this->actingAs($bursar, 'sanctum')->getJson("http://crescent.schoolpilot.test/api/v1/finance/defaulters?term_id={$this->term->id}{$qs}")->assertOk();

        $this->assertCount(1, $get("&arm_id={$this->jss2a->id}")->json('defaulters'));
        $this->assertCount(2, $get('&section=secondary')->json('defaulters'));
        $this->assertSame('A', $get("&arm_id={$this->jss2a->id}")->json('defaulters.0.arm'));
    }

    public function test_teachers_parents_and_students_cannot_see_it()
    {
        foreach (['teacher', 'parent', 'student'] as $role) {
            $this->report($this->user($role))->assertForbidden();
        }
    }
}
