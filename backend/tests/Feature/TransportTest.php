<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** School bus: riders and the bus fee, upkeep, papers and reminders. */
class TransportTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $admin;
    private User $bursar;
    private User $parent;
    private User $otherParent;
    private Student $rider;
    private Student $walker;
    private Term $term;
    private int $bus;
    private string $host = 'http://hilltop.schoolpilot.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-09 09:00:00');
        $this->school = School::create(['name' => 'Hilltop', 'slug' => 'hilltop', 'subdomain' => 'hilltop', 'domain' => 'hilltop.schoolpilot.test']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'name' => 'JSS 1']);
        $this->admin = $this->user('Mrs Adeyemi', 'school_admin');
        $this->bursar = $this->user('Mr Eze', 'bursar');
        $this->parent = $this->user('Mr Obi', 'parent');
        $this->otherParent = $this->user('Mrs Bello', 'parent');
        $this->rider = Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('Ada Obi', 'student')->id, 'class_id' => $class->id, 'admission_number' => 'HT/1', 'status' => 'active']);
        $this->walker = Student::create(['school_id' => $this->school->id, 'user_id' => $this->user('Tunde Bello', 'student')->id, 'class_id' => $class->id, 'admission_number' => 'HT/2', 'status' => 'active']);
        Guardian::create(['school_id' => $this->school->id, 'user_id' => $this->parent->id])->students()->attach($this->rider->id);
        Guardian::create(['school_id' => $this->school->id, 'user_id' => $this->otherParent->id])->students()->attach($this->walker->id);

        $session = AcademicSession::create(['school_id' => $this->school->id, 'name' => '2026/2027', 'start_date' => now()->subMonth(), 'end_date' => now()->addYear(), 'is_current' => true]);
        $this->term = Term::create(['school_id' => $this->school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(), 'is_current' => true]);

        $this->bus = DB::table('buses')->insertGetId(['school_id' => $this->school->id, 'bus_number' => 'Bus 1', 'driver_name' => 'Mr Musa', 'driver_phone' => '08031234567', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name, string $role): User
    {
        $u = User::create(['name' => $name, 'email' => str()->slug($name).'@hilltop.test', 'password' => bcrypt('password123')]);
        $u->userProfile()->create(['school_id' => $this->school->id, 'role' => $role]);

        return $u;
    }

    private function as(User $u)
    {
        return $this->actingAs($u, 'sanctum');
    }

    private function signUp(Student $s): int
    {
        return $this->as($this->admin)->postJson("{$this->host}/transport/riders", ['student_id' => $s->id, 'bus_id' => $this->bus, 'pickup_point' => 'Ikeja under-bridge'])->assertCreated()->json('id');
    }

    private function invoice(Student $s, float $total = 100000): Invoice
    {
        return Invoice::create(['school_id' => $this->school->id, 'term_id' => $this->term->id, 'student_id' => $s->id, 'invoice_number' => 'INV-'.$s->id, 'total_amount' => $total, 'amount_paid' => 0, 'status' => 'unpaid']);
    }

    public function test_only_students_on_the_bus_are_billed_the_flat_fee_once_per_term(): void
    {
        $this->as($this->admin)->putJson("{$this->host}/transport/settings", ['termly_fee' => 45000])->assertOk();
        $this->signUp($this->rider);
        $riderInvoice = $this->invoice($this->rider);
        $walkerInvoice = $this->invoice($this->walker);

        $this->as($this->bursar)->postJson("{$this->host}/transport/bill", ['term_id' => $this->term->id])->assertOk()->assertJson(['created' => 1, 'posted' => 1]);
        $this->as($this->bursar)->postJson("{$this->host}/transport/bill", ['term_id' => $this->term->id])->assertOk()->assertJson(['created' => 0, 'posted' => 0]);

        $this->assertEquals(145000, (float) $riderInvoice->fresh()->total_amount);
        $this->assertEquals(100000, (float) $walkerInvoice->fresh()->total_amount);
        $this->assertTrue(DB::table('invoice_items')->where('invoice_id', $riderInvoice->id)->where('title', 'School bus')->exists());
    }

    public function test_a_fee_waits_for_the_invoice_and_a_student_who_stopped_is_not_billed(): void
    {
        $this->as($this->admin)->putJson("{$this->host}/transport/settings", ['termly_fee' => 45000]);
        $this->signUp($this->rider);
        $stopped = $this->signUp($this->walker);
        $this->as($this->admin)->postJson("{$this->host}/transport/riders/{$stopped}/end")->assertOk();

        $this->as($this->admin)->postJson("{$this->host}/transport/bill", ['term_id' => $this->term->id])->assertJson(['created' => 1, 'awaiting_invoice' => 1]);
        $invoice = $this->invoice($this->rider);
        $this->as($this->admin)->postJson("{$this->host}/transport/bill", ['term_id' => $this->term->id])->assertJson(['posted' => 1]);

        $this->assertEquals(145000, (float) $invoice->fresh()->total_amount);
        $this->assertSame(0, DB::table('transport_charges')->where('student_id', $this->walker->id)->count());
    }

    public function test_billing_needs_a_fee_and_a_student_is_listed_once(): void
    {
        $this->signUp($this->rider);
        $this->as($this->admin)->postJson("{$this->host}/transport/bill", ['term_id' => $this->term->id])->assertStatus(409);
        $this->as($this->admin)->postJson("{$this->host}/transport/riders", ['student_id' => $this->rider->id])->assertStatus(409);
        $this->as($this->bursar)->postJson("{$this->host}/transport/riders", ['student_id' => $this->walker->id])->assertForbidden();
    }

    public function test_a_breakdown_takes_the_bus_off_the_road_and_tells_only_its_riders_parents(): void
    {
        $this->signUp($this->rider);

        $this->as($this->admin)->postJson("{$this->host}/transport/buses/{$this->bus}/logs", [
            'kind' => 'breakdown', 'logged_on' => '2026-10-09', 'description' => 'Gearbox failed on Ikorodu Road', 'tell_parents' => true,
        ])->assertCreated()->assertJsonFragment(['message' => 'Bus marked off the road. 1 parent(s) told.']);

        $this->assertSame('off_road', DB::table('buses')->where('id', $this->bus)->value('status'));
        $this->assertTrue(DB::table('notification_logs')->where('user_id', $this->parent->id)->where('category', 'transport')->exists());
        $this->assertFalse(DB::table('notification_logs')->where('user_id', $this->otherParent->id)->exists());
        $this->as($this->parent)->getJson("{$this->host}/parent/children/{$this->rider->id}/transport")->assertJsonPath('bus.in_service', false);

        $this->as($this->admin)->postJson("{$this->host}/transport/buses/{$this->bus}/back-in-service")->assertOk();
        $this->assertSame('in_service', DB::table('buses')->where('id', $this->bus)->value('status'));
        $this->assertNotNull(DB::table('bus_maintenance_logs')->where('kind', 'breakdown')->value('back_in_service_at'));
    }

    public function test_fuel_and_services_keep_the_odometer_and_show_cost_per_km(): void
    {
        foreach ([[10000, 50000], [10500, 40000], [11000, 45000]] as [$km, $cost]) {
            $this->as($this->admin)->postJson("{$this->host}/transport/buses/{$this->bus}/logs", ['kind' => 'fuel', 'logged_on' => '2026-10-01', 'cost' => $cost, 'litres' => 50, 'odometer_km' => $km])->assertCreated();
        }
        // A late receipt with an older reading doesn't wind the odometer back.
        $this->as($this->admin)->postJson("{$this->host}/transport/buses/{$this->bus}/logs", ['kind' => 'repair', 'logged_on' => '2026-09-20', 'description' => 'Brake pads', 'cost' => 30000, 'odometer_km' => 9800])->assertCreated();

        $bus = collect($this->as($this->admin)->getJson("{$this->host}/transport/fleet")->assertOk()->json('data'))->firstWhere('id', $this->bus);
        $this->assertSame(11000, $bus['odometer_km']);
        $this->assertEquals(85, $bus['fuel_cost_per_km']); // (40,000 + 45,000) / 1,000 km
        $this->assertEquals(165000, $bus['costs_this_year']);
    }

    public function test_papers_and_services_are_reminded_once(): void
    {
        $this->as($this->admin)->postJson("{$this->host}/transport/buses/{$this->bus}/documents", ['kind' => 'insurance', 'expires_on' => '2026-10-20'])->assertCreated();
        $later = $this->as($this->admin)->postJson("{$this->host}/transport/buses/{$this->bus}/documents", ['kind' => 'roadworthiness', 'expires_on' => '2027-06-01'])->json('document.id');
        $this->as($this->admin)->postJson("{$this->host}/transport/buses/{$this->bus}/logs", ['kind' => 'service', 'logged_on' => '2026-07-01', 'description' => 'Full service', 'next_service_on' => '2026-10-12'])->assertCreated();

        $this->artisan('transport:daily')->expectsOutputToContain('1 papers reminder(s); 1 service reminder(s).')->assertSuccessful();
        $this->artisan('transport:daily')->expectsOutputToContain('0 papers reminder(s); 0 service reminder(s).')->assertSuccessful();
        $this->assertSame(2, DB::table('notification_logs')->where('user_id', $this->admin->id)->where('category', 'transport')->count());

        // Renewing with a new date re-arms the reminder for next time.
        $this->as($this->admin)->putJson("{$this->host}/transport/documents/{$later}", ['expires_on' => '2026-10-25'])->assertOk();
        $this->artisan('transport:daily')->expectsOutputToContain('1 papers reminder(s)')->assertSuccessful();
    }

    public function test_parents_see_only_their_own_child_and_the_transport_switch_closes_it(): void
    {
        $this->signUp($this->rider);

        $this->as($this->parent)->getJson("{$this->host}/parent/children/{$this->rider->id}/transport")->assertOk()
            ->assertJson(['rides' => true, 'pickup_point' => 'Ikeja under-bridge', 'bus' => ['bus_number' => 'Bus 1', 'driver_phone' => '08031234567', 'in_service' => true]]);
        $this->as($this->otherParent)->getJson("{$this->host}/parent/children/{$this->rider->id}/transport")->assertNotFound();
        $this->as($this->otherParent)->getJson("{$this->host}/parent/children/{$this->walker->id}/transport")->assertJson(['rides' => false]);

        $this->school->forceFill(['module_switches' => json_encode(['transport' => false])])->save();
        $this->as($this->admin)->getJson("{$this->host}/transport/riders")->assertForbidden();
        $this->as($this->parent)->getJson("{$this->host}/parent/children/{$this->rider->id}/transport")->assertJson(['rides' => false]);
    }
}
