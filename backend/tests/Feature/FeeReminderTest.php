<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Chasing the debtor list (§7.12).
 *
 * The defaulter dashboard could say who owed and then stopped. The web UI's
 * "Send SMS Reminder" button had no handler at all, and the defaulters endpoint
 * returned nothing that could be used to contact anyone.
 */
class FeeReminderTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private Term $term;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs the sync queue driver, so a queued reminder is
        // delivered by the time the request returns.
        Http::fake(['api.ng.termii.com/*' => Http::response(['message_id' => 'abc'], 200)]);
        config()->set('services.sms.api_key', 'live-key');

        $this->school = School::create([
            'name' => 'Greenfield Academy', 'slug' => 'greenfield',
            'subdomain' => 'greenfield', 'domain' => 'greenfield.schoolpilot.test',
        ]);

        $this->admin = $this->user('Bursar', 'bursar@greenfield.test', 'school_admin');
        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);

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

    private function user(string $name, string $email, string $role, ?string $phone = null): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password123')]);
        $user->userProfile()->create([
            'school_id' => $this->school->id, 'role' => $role, 'phone' => $phone,
        ]);

        return $user;
    }

    private function student(string $name, ?User $guardianUser = null): Student
    {
        $studentUser = $this->user(
            $name,
            strtolower(str_replace(' ', '', $name)) . '@greenfield.test',
            'student'
        );

        $student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $studentUser->id,
            'class_id' => $this->class->id,
            'admission_number' => 'ADM' . $studentUser->id,
            'status' => 'active',
        ]);

        if ($guardianUser) {
            $guardian = Guardian::firstOrCreate(
                ['school_id' => $this->school->id, 'user_id' => $guardianUser->id],
                ['relationship' => 'mother']
            );
            $student->guardians()->attach($guardian->id);
        }

        return $student;
    }

    private function invoice(Student $student, float $total, float $paid = 0, int $daysOverdue = 20): Invoice
    {
        return Invoice::create([
            'school_id' => $this->school->id,
            'term_id' => $this->term->id,
            'student_id' => $student->id,
            'invoice_number' => 'INV-' . uniqid(),
            'total_amount' => $total,
            'amount_paid' => $paid,
            'status' => $paid <= 0 ? 'unpaid' : 'partial',
            'due_date' => now()->subDays($daysOverdue),
        ]);
    }

    private function url(string $path): string
    {
        return 'http://greenfield.schoolpilot.test/api/v1' . $path;
    }

    private function remind(array $payload = [])
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/finance/defaulters/remind'), $payload + ['channels' => ['sms']]);
    }

    public function test_a_reminder_reaches_the_guardian_with_the_real_balance()
    {
        $parent = $this->user('Mrs Adeyemi', 'adeyemi@greenfield.test', 'parent', '+2348030000001');
        $student = $this->student('Tunde Adeyemi', $parent);
        $this->invoice($student, 100000, 40000);

        $this->remind()
            ->assertStatus(202)
            ->assertJsonPath('reminders_queued', 1)
            ->assertJsonStructure(['batch_id', 'status_url', 'unreachable']);

        $log = NotificationLog::first();

        $this->assertSame($parent->id, $log->user_id);
        $this->assertSame('sms', $log->channel);
        $this->assertSame('sent', $log->status);
        $this->assertSame('fee_reminder', $log->category);
        $this->assertSame($this->admin->id, $log->sent_by);

        $this->assertStringContainsString('Mrs Adeyemi', $log->body);
        $this->assertStringContainsString('Tunde Adeyemi', $log->body);
        // The outstanding balance, not the invoice total.
        $this->assertStringContainsString('₦60,000.00', $log->body);
    }

    /**
     * SMS is billed per message. One parent, three children owing, one text.
     */
    public function test_a_parent_with_several_children_gets_one_message()
    {
        $parent = $this->user('Mr Okoro', 'okoro@greenfield.test', 'parent', '+2348030000002');

        $this->invoice($this->student('Chidi Okoro', $parent), 50000);
        $this->invoice($this->student('Ngozi Okoro', $parent), 30000);
        $this->invoice($this->student('Emeka Okoro', $parent), 20000);

        $this->remind()
            ->assertStatus(202)
            ->assertJsonPath('reminders_queued', 1);

        $this->assertSame(1, NotificationLog::count());

        $body = NotificationLog::first()->body;

        $this->assertStringContainsString('Chidi Okoro', $body);
        $this->assertStringContainsString('Ngozi Okoro', $body);
        $this->assertStringContainsString('Emeka Okoro', $body);
        $this->assertStringContainsString('Total outstanding: ₦100,000.00', $body);
    }

    /** Both parents on record should hear about it. */
    public function test_every_guardian_of_record_is_reminded()
    {
        $mother = $this->user('Mrs Bello', 'bello.m@greenfield.test', 'parent', '+2348030000003');
        $father = $this->user('Mr Bello', 'bello.f@greenfield.test', 'parent', '+2348030000004');

        $student = $this->student('Amina Bello', $mother);
        $guardian = Guardian::firstOrCreate(
            ['school_id' => $this->school->id, 'user_id' => $father->id],
            ['relationship' => 'father']
        );
        $student->guardians()->attach($guardian->id);

        $this->invoice($student, 45000);

        $this->remind()->assertStatus(202)->assertJsonPath('reminders_queued', 2);

        $this->assertEqualsCanonicalizing(
            [$mother->id, $father->id],
            NotificationLog::pluck('user_id')->all()
        );
    }

    /** No guardian on file — common for SS3. Better than not chasing at all. */
    public function test_a_student_without_a_guardian_is_reminded_directly()
    {
        $student = $this->student('Independent Student');
        $student->user->userProfile->update(['phone' => '+2348030000005']);

        $this->invoice($student, 45000);

        $this->remind()->assertStatus(202)->assertJsonPath('reminders_queued', 1);

        $this->assertSame($student->user_id, NotificationLog::first()->user_id);
    }

    /**
     * A family with no number on file has to be chased by phone, and the bursar
     * needs to be told which ones those are rather than assuming it went out.
     */
    public function test_a_contact_without_a_phone_number_is_recorded_as_failed()
    {
        $parent = $this->user('No Phone Parent', 'nophone@greenfield.test', 'parent');
        $this->invoice($this->student('Unreachable Child', $parent), 45000);

        $this->remind()->assertStatus(202)->assertJsonPath('reminders_queued', 1);

        $log = NotificationLog::first();
        $this->assertSame('failed', $log->status);
        $this->assertSame('No phone number on file.', $log->failure_reason);
    }

    public function test_settled_families_are_never_reminded()
    {
        $owing = $this->user('Owing Parent', 'owing@greenfield.test', 'parent', '+2348030000006');
        $settled = $this->user('Settled Parent', 'settled@greenfield.test', 'parent', '+2348030000007');

        $this->invoice($this->student('Owing Child', $owing), 50000, 10000);
        $this->invoice($this->student('Settled Child', $settled), 50000, 50000);

        $this->remind()->assertStatus(202)->assertJsonPath('reminders_queued', 1);

        $this->assertSame($owing->id, NotificationLog::first()->user_id);
    }

    public function test_a_bursar_can_remind_only_the_invoices_they_ticked()
    {
        $first = $this->user('First Parent', 'first@greenfield.test', 'parent', '+2348030000008');
        $second = $this->user('Second Parent', 'second@greenfield.test', 'parent', '+2348030000009');

        $chosen = $this->invoice($this->student('First Child', $first), 50000);
        $this->invoice($this->student('Second Child', $second), 50000);

        $this->remind(['invoice_ids' => [$chosen->id]])
            ->assertStatus(202)
            ->assertJsonPath('reminders_queued', 1);

        $this->assertSame($first->id, NotificationLog::first()->user_id);
    }

    /** An id from another school must not be reachable by ticking it. */
    public function test_another_schools_invoice_cannot_be_chased()
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

        $otherUser = User::create([
            'name' => 'Their Student', 'email' => 'theirs@rival.test', 'password' => bcrypt('password123'),
        ]);
        $otherUser->userProfile()->create(['school_id' => $other->id, 'role' => 'student']);

        $otherStudent = Student::create([
            'school_id' => $other->id, 'user_id' => $otherUser->id,
            'admission_number' => 'R-1', 'status' => 'active',
        ]);

        $foreign = Invoice::create([
            'school_id' => $other->id, 'term_id' => $otherTerm->id, 'student_id' => $otherStudent->id,
            'invoice_number' => 'INV-RIVAL-1', 'total_amount' => 90000, 'amount_paid' => 0,
            'status' => 'unpaid', 'due_date' => now()->subDays(10),
        ]);

        $this->remind(['invoice_ids' => [$foreign->id]])->assertStatus(422);

        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_a_class_sweep_only_chases_that_class()
    {
        $otherClass = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'SS 1']);

        $juniorParent = $this->user('Junior Parent', 'jp@greenfield.test', 'parent', '+2348030000010');
        $seniorParent = $this->user('Senior Parent', 'sp@greenfield.test', 'parent', '+2348030000011');

        $this->invoice($this->student('Junior Child', $juniorParent), 45000);

        $senior = $this->student('Senior Child', $seniorParent);
        $senior->update(['class_id' => $otherClass->id]);
        $this->invoice($senior, 55000);

        $this->remind(['class_id' => $this->class->id])
            ->assertStatus(202)
            ->assertJsonPath('reminders_queued', 1);

        $this->assertSame($juniorParent->id, NotificationLog::first()->user_id);
    }

    /** Push is free, SMS is not. The bursar picks, deliberately. */
    public function test_channels_must_be_chosen_explicitly()
    {
        $parent = $this->user('Some Parent', 'some@greenfield.test', 'parent', '+2348030000012');
        $this->invoice($this->student('Some Child', $parent), 45000);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($this->url('/finance/defaulters/remind'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('channels');

        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_an_empty_selection_sends_nothing()
    {
        $this->remind()
            ->assertStatus(422)
            ->assertJsonPath('reminders_queued', 0);

        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_a_custom_note_is_appended()
    {
        $parent = $this->user('Noted Parent', 'noted@greenfield.test', 'parent', '+2348030000013');
        $this->invoice($this->student('Noted Child', $parent), 45000);

        $this->remind(['note' => 'Please see the bursar before Friday assembly.'])
            ->assertStatus(202);

        $this->assertStringContainsString(
            'Please see the bursar before Friday assembly.',
            NotificationLog::first()->body
        );
    }

    public function test_a_teacher_cannot_send_fee_reminders()
    {
        $teacher = $this->user('Teacher', 'teacher@greenfield.test', 'teacher');

        $this->actingAs($teacher, 'sanctum')
            ->postJson($this->url('/finance/defaulters/remind'), ['channels' => ['sms']])
            ->assertStatus(403);
    }

    /**
     * The debtor list says who could be reached, but not on what number —
     * the reminder is sent server-side, so the numbers have no reason to be in
     * every browser session (NDPA data minimisation, doc §12).
     */
    public function test_the_debtor_list_names_contacts_without_exposing_phone_numbers()
    {
        $parent = $this->user('Mrs Adeyemi', 'adeyemi@greenfield.test', 'parent', '+2348030000014');
        $this->invoice($this->student('Tunde Adeyemi', $parent), 45000);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/finance/defaulters'))
            ->assertStatus(200)
            ->assertJsonPath('defaulters.0.contacts.0', 'Mrs Adeyemi')
            ->assertJsonPath('defaulters.0.contactable', true);

        $this->assertStringNotContainsString('+2348030000014', $response->getContent());
    }

    public function test_a_family_with_no_number_is_flagged_as_not_contactable()
    {
        $parent = $this->user('No Phone Parent', 'nophone@greenfield.test', 'parent');
        $this->invoice($this->student('Unreachable Child', $parent), 45000);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson($this->url('/finance/defaulters'))
            ->assertStatus(200)
            ->assertJsonPath('defaulters.0.contactable', false);
    }
}
