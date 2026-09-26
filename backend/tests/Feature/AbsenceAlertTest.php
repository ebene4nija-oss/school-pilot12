<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Telling a parent their child was marked absent.
 *
 * Off unless the school switches it on and the parent opts in for that child;
 * at most once per child, per parent, per day; only for today's register.
 */
class AbsenceAlertTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $teacher;

    private User $parent;

    private Guardian $guardian;

    private Student $ada;

    private Student $bola;

    private Term $term;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs the sync queue, so the alert job runs inside the request.
        Http::fake(['api.ng.termii.com/*' => Http::response(['message_id' => 'abc'], 200)]);
        config()->set('services.sms.api_key', 'live-key');

        $this->school = School::create(['name' => 'Greenfield Academy', 'slug' => 'greenfield', 'subdomain' => 'greenfield', 'status' => 'active']);

        $this->admin = $this->makeUser('Greenfield Admin', 'admin@greenfield.test', 'school_admin');
        $this->teacher = $this->makeUser('Mrs Adebayo', 'teacher@greenfield.test', 'teacher');
        $this->teacher->userProfile->update(['sees_all_students' => true]);
        // A phone-only parent: no email, but a number SMS can reach.
        $this->parent = $this->makeUser('Mrs Obi', null, 'parent', '08031111111');

        $session = AcademicSession::create([
            'school_id' => $this->school->id, 'name' => '2025/2026',
            'start_date' => now()->subMonths(6), 'end_date' => now()->addMonths(6), 'is_current' => true,
        ]);
        $this->term = Term::create([
            'school_id' => $this->school->id, 'session_id' => $session->id,
            'name' => 'First Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(),
        ]);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);

        $this->ada = $this->enrol('Ada Obi', $class);
        $this->bola = $this->enrol('Bola Obi', $class);

        $this->guardian = Guardian::create(['school_id' => $this->school->id, 'user_id' => $this->parent->id, 'relationship' => 'mother']);
        $this->guardian->students()->attach([$this->ada->id => ['is_primary' => true], $this->bola->id => ['is_primary' => true]]);
    }

    private function makeUser(string $name, ?string $email, string $role, ?string $phone = null): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('password')]);
        $user->userProfile()->create(['school_id' => $this->school->id, 'role' => $role, 'phone' => $phone]);

        return $user;
    }

    private function enrol(string $name, SchoolClass $class): Student
    {
        $user = User::create(['name' => $name, 'email' => null, 'password' => bcrypt('password')]);

        return Student::create([
            'school_id' => $this->school->id, 'user_id' => $user->id, 'class_id' => $class->id,
            'admission_number' => 'GF-'.$user->id, 'status' => 'active',
        ]);
    }

    private function register(array $statuses, ?string $date = null, string $key = 'register-key-1')
    {
        $records = [];
        foreach ($statuses as $studentId => $status) {
            $records[] = ['student_id' => $studentId, 'status' => $status];
        }

        return $this->actingAs($this->teacher, 'sanctum')->postJson('/api/v1/attendance/bulk', [
            'term_id' => $this->term->id,
            'date' => $date ?? now()->toDateString(),
            'records' => $records,
            'idempotency_key' => $key,
        ])->assertOk();
    }

    private function schoolOn(bool $sms = true): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/absence-alerts', ['enabled' => true, 'sms' => $sms])
            ->assertOk();
    }

    private function parentOptsIn(Student $child): void
    {
        $this->actingAs($this->parent, 'sanctum')
            ->putJson("/api/v1/parent/children/{$child->id}/absence-alerts", ['enabled' => true])
            ->assertOk();
    }

    private function smsSent(): int
    {
        return count(Http::recorded(fn ($request) => str_contains($request->url(), 'termii')));
    }

    // --- Off unless both sides asked ------------------------------------

    public function test_nothing_is_sent_while_the_school_has_alerts_off(): void
    {
        $this->register([$this->ada->id => 'absent']);

        $this->assertSame(0, $this->smsSent());
        $this->assertDatabaseCount('absence_alerts', 0);
    }

    public function test_a_parent_cannot_opt_in_before_the_school_switches_alerts_on(): void
    {
        $this->actingAs($this->parent, 'sanctum')
            ->putJson("/api/v1/parent/children/{$this->ada->id}/absence-alerts", ['enabled' => true])
            ->assertStatus(409);
    }

    public function test_nothing_is_sent_to_a_parent_who_has_not_opted_in(): void
    {
        $this->schoolOn();
        $this->register([$this->ada->id => 'absent']);

        $this->assertSame(0, $this->smsSent());
    }

    // --- Sent when both have ---------------------------------------------

    public function test_an_opted_in_parent_is_texted_once_about_that_child_only(): void
    {
        $this->schoolOn();
        $this->parentOptsIn($this->ada);

        $this->register([$this->ada->id => 'absent', $this->bola->id => 'absent']);

        // Ada's alert only: the parent opted in for Ada, not Bola.
        $this->assertSame(1, $this->smsSent());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'termii') && str_contains($r['sms'] ?? '', 'Ada was marked absent at Greenfield Academy today'));
        $this->assertDatabaseHas('absence_alerts', ['student_id' => $this->ada->id, 'outcome' => 'sent']);
        $this->assertDatabaseMissing('absence_alerts', ['student_id' => $this->bola->id]);
    }

    public function test_a_resubmitted_register_does_not_send_a_second_alert(): void
    {
        $this->schoolOn();
        $this->parentOptsIn($this->ada);

        $this->register([$this->ada->id => 'absent'], null, 'register-key-1');
        $this->register([$this->ada->id => 'present'], null, 'register-key-2');
        $this->register([$this->ada->id => 'absent'], null, 'register-key-3');

        $this->assertSame(1, $this->smsSent());
    }

    public function test_back_filling_an_earlier_day_does_not_alert(): void
    {
        $this->schoolOn();
        $this->parentOptsIn($this->ada);

        $this->register([$this->ada->id => 'absent'], now()->subDays(3)->toDateString());

        $this->assertSame(0, $this->smsSent());
    }

    public function test_without_sms_allowed_the_school_is_not_billed(): void
    {
        $this->schoolOn(sms: false);
        $this->parentOptsIn($this->ada);

        $this->register([$this->ada->id => 'absent']);

        // Push only; this parent has no app, so nothing is delivered and no SMS is paid for.
        $this->assertSame(0, $this->smsSent());
        $this->assertDatabaseHas('absence_alerts', ['student_id' => $this->ada->id, 'outcome' => 'failed']);
    }

    public function test_switching_the_school_off_stops_alerts_for_opted_in_parents(): void
    {
        $this->schoolOn();
        $this->parentOptsIn($this->ada);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/school/absence-alerts', ['enabled' => false])
            ->assertOk();

        $this->register([$this->ada->id => 'absent']);

        $this->assertSame(0, $this->smsSent());
    }

    // --- Who can set what ------------------------------------------------

    public function test_the_school_office_can_opt_in_a_parent_who_asked_and_it_is_audited(): void
    {
        $this->schoolOn();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/students/{$this->ada->id}/absence-alerts")
            ->assertOk()
            ->assertJsonPath('guardians.0.name', 'Mrs Obi')
            ->assertJsonPath('guardians.0.has_phone', true)
            ->assertJsonPath('guardians.0.enabled', false);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/students/{$this->ada->id}/guardians/{$this->guardian->id}/absence-alerts", ['enabled' => true])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'absence_alerts.set_by_school']);

        $this->register([$this->ada->id => 'absent']);
        $this->assertSame(1, $this->smsSent());
    }

    public function test_a_parent_sees_their_children_and_cannot_opt_in_for_someone_elses(): void
    {
        $this->schoolOn();

        $this->actingAs($this->parent, 'sanctum')
            ->getJson('/api/v1/parent/absence-alerts')
            ->assertOk()
            ->assertJsonPath('offered', true)
            ->assertJsonCount(2, 'children');

        $stranger = $this->makeUser('Mr Stranger', 'stranger@greenfield.test', 'parent', '08099999999');
        Guardian::create(['school_id' => $this->school->id, 'user_id' => $stranger->id, 'relationship' => 'father']);

        $this->actingAs($stranger, 'sanctum')
            ->putJson("/api/v1/parent/children/{$this->ada->id}/absence-alerts", ['enabled' => true])
            ->assertNotFound();
    }

    public function test_only_a_school_admin_switches_alerts_on_and_it_is_audited(): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->putJson('/api/v1/school/absence-alerts', ['enabled' => true])
            ->assertForbidden();

        $this->schoolOn();

        $this->assertDatabaseHas('audit_logs', ['action' => 'absence_alerts.school_changed']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/school/absence-alerts')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('sms', true)
            ->assertJsonPath('parents_opted_in', 0);
    }

    public function test_the_alert_names_only_the_childs_first_name_and_the_school(): void
    {
        $this->schoolOn();
        $this->parentOptsIn($this->ada);
        $this->register([$this->ada->id => 'absent']);

        $sms = collect(Http::recorded())->map(fn ($pair) => $pair[0]['sms'] ?? '')->filter()->first();

        $this->assertStringNotContainsString('Obi', $sms);
        $this->assertLessThanOrEqual(160, strlen($sms));
        $this->assertSame(1, DB::table('absence_alerts')->count());
    }
}
