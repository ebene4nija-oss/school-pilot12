<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\ClassTeacherAssignment;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\TeacherScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Who sees which children, and who works the fee book.
 *
 * - A teacher sees the students they teach, not the whole school, unless the
 *   school admin grants them whole-school access (audited).
 * - A bursar reaches fees, payments, school accounts and result-PIN sales, and
 *   not results, health records, full student records, gateway keys or payroll.
 * - A family's invoice and receipt are that family's: another parent in the
 *   same school cannot read them by changing the id.
 */
class PeopleAccessTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $teacher;

    private User $bursar;

    private SchoolClass $jss1;

    private SchoolClass $jss2;

    private Arm $gold;

    private Arm $silver;

    private Student $goldPupil;

    private Student $silverPupil;

    private Student $jss2Pupil;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();
        TeacherScope::forget();

        $this->school = School::create(['name' => 'Greenfield Academy', 'slug' => 'greenfield', 'subdomain' => 'greenfield', 'status' => 'active']);

        $this->admin = $this->makeUser('Greenfield Admin', 'admin@greenfield.test', 'school_admin');
        $this->teacher = $this->makeUser('Mrs Adebayo', 'teacher@greenfield.test', 'teacher');
        $this->bursar = $this->makeUser('Mr Okafor', 'bursar@greenfield.test', 'bursar');

        $session = AcademicSession::create([
            'school_id' => $this->school->id, 'name' => '2025/2026',
            'start_date' => '2025-09-01', 'end_date' => '2026-07-31', 'is_current' => true,
        ]);
        $this->term = Term::create([
            'school_id' => $this->school->id, 'session_id' => $session->id,
            'name' => 'First Term', 'start_date' => now()->subMonths(3), 'end_date' => now()->addMonth(),
        ]);

        $this->jss1 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1', 'order_index' => 1]);
        $this->jss2 = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 2', 'order_index' => 2]);
        $this->gold = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'name' => 'Gold']);
        $this->silver = Arm::create(['school_id' => $this->school->id, 'class_id' => $this->jss1->id, 'name' => 'Silver']);

        $this->goldPupil = $this->enrol('Ada Gold', $this->jss1, $this->gold);
        $this->silverPupil = $this->enrol('Bola Silver', $this->jss1, $this->silver);
        $this->jss2Pupil = $this->enrol('Chidi Two', $this->jss2, null);

        // Mrs Adebayo is form teacher of JSS 1 Gold this session.
        ClassTeacherAssignment::create([
            'school_id' => $this->school->id, 'session_id' => $session->id,
            'class_id' => $this->jss1->id, 'arm_id' => $this->gold->id,
            'form_teacher_id' => $this->teacher->id,
        ]);
    }

    private function makeUser(string $name, string $email, string $role): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $user;
    }

    private function enrol(string $name, SchoolClass $class, ?Arm $arm): Student
    {
        $user = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@greenfield.test', 'password' => bcrypt('password')]);

        return Student::create([
            'school_id' => $this->school->id, 'user_id' => $user->id,
            'admission_number' => 'GF-'.$user->id, 'status' => 'active',
            'class_id' => $class->id, 'arm_id' => $arm?->id,
        ]);
    }

    private function invoiceFor(Student $student): Invoice
    {
        return Invoice::create([
            'school_id' => $this->school->id, 'term_id' => $this->term->id, 'student_id' => $student->id,
            'invoice_number' => 'INV-'.uniqid(), 'total_amount' => 150000, 'amount_paid' => 0,
            'status' => 'unpaid', 'due_date' => now()->addMonth(),
        ]);
    }

    private function names($response): array
    {
        return collect($response->json('data'))->pluck('name')->sort()->values()->all();
    }

    // --- Teachers see the students they teach -------------------------------

    public function test_a_form_teacher_lists_only_their_arm(): void
    {
        $response = $this->actingAs($this->teacher, 'sanctum')->getJson('/api/v1/students')->assertOk();

        $this->assertSame(['Ada Gold'], $this->names($response));
    }

    public function test_a_teacher_cannot_open_a_record_outside_their_classes(): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/v1/students/{$this->goldPupil->id}")
            ->assertOk();

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/v1/students/{$this->silverPupil->id}")
            ->assertForbidden();

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/v1/students/{$this->jss2Pupil->id}")
            ->assertForbidden();
    }

    public function test_teaching_a_subject_in_a_class_covers_every_arm_of_it(): void
    {
        $subjectId = DB::table('subjects')->insertGetId([
            'school_id' => $this->school->id, 'name' => 'Mathematics', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('teacher_subjects')->insert([
            'school_id' => $this->school->id, 'teacher_id' => $this->teacher->id,
            'subject_id' => $subjectId, 'class_id' => $this->jss2->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        TeacherScope::forget();

        $response = $this->actingAs($this->teacher, 'sanctum')->getJson('/api/v1/students')->assertOk();

        $this->assertSame(['Ada Gold', 'Chidi Two'], $this->names($response));
    }

    public function test_a_teacher_with_no_classes_sees_no_one(): void
    {
        $newcomer = $this->makeUser('New Teacher', 'new@greenfield.test', 'teacher');

        $this->actingAs($newcomer, 'sanctum')
            ->getJson('/api/v1/students')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_minimal_lookup_still_finds_any_child_for_a_teacher(): void
    {
        // Name, admission number and class only — what walking a child to the
        // nurse needs, from any class.
        $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/v1/students/lookup?search=Chidi')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Chidi Two');
    }

    public function test_a_school_admin_still_sees_everyone(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/students')->assertOk();

        $this->assertCount(3, $response->json('data'));
    }

    // --- Whole-school access, granted by the school admin ------------------

    public function test_a_granted_teacher_sees_the_whole_school_and_the_grant_is_audited(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/staff/{$this->teacher->id}/student-access", ['sees_all_students' => true])
            ->assertOk()
            ->assertJsonPath('data.sees_all_students', true);

        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.student_access_changed', 'auditable_id' => $this->teacher->id]);

        $this->actingAs($this->teacher->fresh(), 'sanctum')
            ->getJson('/api/v1/students')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->actingAs($this->teacher->fresh(), 'sanctum')
            ->getJson("/api/v1/students/{$this->jss2Pupil->id}")
            ->assertOk();

        // And taken back.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/staff/{$this->teacher->id}/student-access", ['sees_all_students' => false])
            ->assertOk();

        $this->actingAs($this->teacher->fresh(), 'sanctum')
            ->getJson("/api/v1/students/{$this->jss2Pupil->id}")
            ->assertForbidden();
    }

    public function test_only_teachers_can_be_granted_and_only_by_a_school_admin(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/staff/{$this->bursar->id}/student-access", ['sees_all_students' => true])
            ->assertStatus(422);

        $this->actingAs($this->teacher, 'sanctum')
            ->putJson("/api/v1/staff/{$this->teacher->id}/student-access", ['sees_all_students' => true])
            ->assertForbidden();

        $elsewhere = School::create(['name' => 'Rival', 'slug' => 'rival', 'subdomain' => 'rival', 'status' => 'active']);
        $stranger = User::create(['name' => 'Rival Teacher', 'email' => 'rt@rival.test', 'password' => bcrypt('password')]);
        $stranger->userProfile()->create(['school_id' => $elsewhere->id, 'role' => 'teacher']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/staff/{$stranger->id}/student-access", ['sees_all_students' => true])
            ->assertNotFound();
    }

    public function test_the_staff_list_reports_the_grant(): void
    {
        $this->teacher->userProfile->update(['sees_all_students' => true]);

        $rows = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/staff/directory')->assertOk()->json('data'))
            ->keyBy('email');

        $this->assertTrue($rows['teacher@greenfield.test']['sees_all_students']);
    }

    // --- The bursar ---------------------------------------------------------

    public function test_a_bursar_works_the_fee_book(): void
    {
        $invoice = $this->invoiceFor($this->jss2Pupil);

        $this->actingAs($this->bursar, 'sanctum')->getJson('/api/v1/finance/fee-structure')->assertOk();
        $this->actingAs($this->bursar, 'sanctum')->getJson('/api/v1/finance/defaulters')->assertOk();
        $this->actingAs($this->bursar, 'sanctum')->getJson('/api/v1/accounting/income-report')->assertOk();
        $this->actingAs($this->bursar, 'sanctum')->getJson('/api/v1/result-pins/inventory')->assertOk();
        $this->actingAs($this->bursar, 'sanctum')->getJson('/api/v1/students/lookup?search=Chidi')->assertOk();

        $this->actingAs($this->bursar, 'sanctum')
            ->getJson("/api/v1/finance/students/{$this->jss2Pupil->id}/statement")
            ->assertOk();

        // Cash at the accounts office is recorded as received, as for an admin.
        $this->actingAs($this->bursar, 'sanctum')
            ->postJson('/api/v1/finance/payments', ['invoice_id' => $invoice->id, 'amount' => 50000, 'gateway' => 'cash'])
            ->assertSuccessful();

        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'status' => 'successful']);
    }

    public function test_a_bursar_cannot_reach_records_results_health_keys_or_payroll(): void
    {
        $this->actingAs($this->bursar, 'sanctum')->getJson("/api/v1/students/{$this->jss2Pupil->id}")->assertForbidden();
        $this->actingAs($this->bursar, 'sanctum')->getJson('/api/v1/students')->assertForbidden();
        $this->actingAs($this->bursar, 'sanctum')->getJson('/api/v1/assessment/broadsheet')->assertForbidden();
        $this->actingAs($this->bursar, 'sanctum')->getJson('/api/v1/health/clinic-visits')->assertForbidden();
        $this->actingAs($this->bursar, 'sanctum')->getJson('/api/v1/finance/gateways')->assertForbidden();
        $this->actingAs($this->bursar, 'sanctum')->postJson('/api/v1/accounting/payroll', [])->assertForbidden();
        $this->actingAs($this->bursar, 'sanctum')->putJson('/api/v1/result-pins/settings', [])->assertForbidden();
    }

    public function test_a_school_admin_can_add_a_bursar(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/staff', ['name' => 'Mrs Bello', 'email' => 'bello@greenfield.test', 'role' => 'bursar'])
            ->assertCreated();

        $this->assertDatabaseHas('user_profiles', ['role' => 'bursar', 'school_id' => $this->school->id]);
    }

    // --- A family's fee documents are that family's ------------------------

    public function test_a_parent_cannot_read_another_familys_invoice_or_receipt(): void
    {
        $mine = $this->invoiceFor($this->goldPupil);
        $theirs = $this->invoiceFor($this->jss2Pupil);

        $parent = $this->makeUser('Mr Gold', 'mr.gold@greenfield.test', 'parent');
        $guardian = Guardian::create(['school_id' => $this->school->id, 'user_id' => $parent->id, 'relationship' => 'father']);
        $guardian->students()->attach($this->goldPupil->id, ['is_primary' => true]);

        $this->actingAs($parent, 'sanctum')->getJson("/api/v1/finance/invoices/{$mine->id}/pdf")->assertOk();
        $this->actingAs($parent, 'sanctum')->getJson("/api/v1/finance/invoices/{$theirs->id}/pdf")->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/finance/payments', ['invoice_id' => $theirs->id, 'amount' => 1000, 'gateway' => 'cash'])
            ->assertSuccessful();
        $paymentId = DB::table('payments')->where('invoice_id', $theirs->id)->value('id');

        $this->actingAs($parent, 'sanctum')->getJson("/api/v1/finance/payments/{$paymentId}/receipt")->assertForbidden();
        $this->actingAs($this->bursar, 'sanctum')->getJson("/api/v1/finance/payments/{$paymentId}/receipt")->assertOk();
    }
}
