<?php

namespace Tests\Feature;

use App\Models\ClinicVisit;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Transport, library, hostel and clinic: the read side.
 *
 * Each of these features could be written to and never read back, so the
 * portal's pages for them were built on invented arrays. These cover the new
 * list endpoints and the allocation rules the hostel writer was missing.
 */
class OperationsReadEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $otherSchool;

    private User $admin;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Greenfield Academy',
            'slug' => 'greenfield',
            'subdomain' => 'greenfield',
            'status' => 'active',
        ]);

        $this->otherSchool = School::create([
            'name' => 'Rival Academy',
            'slug' => 'rival',
            'subdomain' => 'rival',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'name' => 'Greenfield Admin',
            'email' => 'admin@greenfield.test',
            'password' => bcrypt('password'),
        ]);
        $this->admin->userProfile()->create([
            'school_id' => $this->school->id,
            'role' => 'school_admin',
        ]);

        $studentUser = User::create([
            'name' => 'Chidi Nnamdi',
            'email' => 'chidi@greenfield.test',
            'password' => bcrypt('password'),
        ]);
        $this->student = Student::create([
            'school_id' => $this->school->id,
            'user_id' => $studentUser->id,
            'admission_number' => 'GRN-001',
            'status' => 'active',
        ]);
    }

    // ---------------------------------------------------------------- transport

    public function test_bus_list_reports_route_position_and_whether_it_is_fresh(): void
    {
        $busId = DB::table('buses')->insertGetId([
            'school_id' => $this->school->id,
            'bus_number' => 'BUS-01',
            'driver_name' => 'Driver John',
            'driver_phone' => '08012345678',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('bus_routes')->insert([
            'school_id' => $this->school->id,
            'bus_id' => $busId,
            'route_name' => 'Lekki Phase 1',
            'is_active' => true,
            'current_lat' => 6.458985,
            'current_lng' => 3.424355,
            // Two hours old: a position, but not a live one.
            'last_ping_at' => now()->subHours(2),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/transport/buses')
            ->assertOk()
            ->assertJsonPath('data.0.bus_number', 'BUS-01')
            ->assertJsonPath('data.0.route_name', 'Lekki Phase 1')
            ->assertJsonPath('data.0.position_is_fresh', false);
    }

    public function test_bus_list_marks_a_recent_ping_as_fresh(): void
    {
        $busId = DB::table('buses')->insertGetId([
            'school_id' => $this->school->id,
            'bus_number' => 'BUS-02',
            'driver_name' => 'Driver Samuel',
            'driver_phone' => '08087654321',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('bus_routes')->insert([
            'school_id' => $this->school->id,
            'bus_id' => $busId,
            'route_name' => 'Ikeja',
            'is_active' => true,
            'current_lat' => 6.59651,
            'current_lng' => 3.34209,
            'last_ping_at' => now()->subMinute(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/transport/buses')
            ->assertOk()
            ->assertJsonPath('data.0.position_is_fresh', true);
    }

    public function test_bus_list_does_not_leak_another_schools_fleet(): void
    {
        DB::table('buses')->insert([
            'school_id' => $this->otherSchool->id,
            'bus_number' => 'RIVAL-01',
            'driver_name' => 'Someone Else',
            'driver_phone' => '08000000000',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/transport/buses')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_can_register_a_bus_with_its_route(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/transport/buses', [
                'bus_number' => 'BUS-09',
                'driver_name' => 'Driver Musa',
                'driver_phone' => '08011112222',
                'route_name' => 'Yaba Loop',
            ])
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/transport/buses')
            ->assertOk()
            ->assertJsonPath('data.0.route_name', 'Yaba Loop');
    }

    // ------------------------------------------------------------------ library

    public function test_book_list_reports_loans_and_overdue_counts(): void
    {
        $bookId = DB::table('books')->insertGetId([
            'school_id' => $this->school->id,
            'title' => 'Things Fall Apart',
            'author' => 'Chinua Achebe',
            'barcode' => 'BC-001',
            'total_copies' => 3,
            'available_copies' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // One current loan, one overdue.
        DB::table('borrow_records')->insert([
            [
                'book_id' => $bookId,
                'user_id' => $this->admin->id,
                'borrowed_at' => now()->subDays(3)->toDateString(),
                'due_date' => now()->addDays(4)->toDateString(),
                'returned_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'book_id' => $bookId,
                'user_id' => $this->admin->id,
                'borrowed_at' => now()->subDays(30)->toDateString(),
                'due_date' => now()->subDays(9)->toDateString(),
                'returned_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/library/books')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Things Fall Apart')
            ->assertJsonPath('data.0.on_loan', 2)
            ->assertJsonPath('data.0.overdue', 1)
            ->assertJsonPath('data.0.available_copies', 1);
    }

    public function test_duplicate_barcode_in_one_school_is_rejected(): void
    {
        $payload = [
            'title' => 'Half of a Yellow Sun',
            'author' => 'Chimamanda Ngozi Adichie',
            'barcode' => 'BC-777',
            'total_copies' => 2,
        ];

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/library/books', $payload)
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/library/books', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('barcode');
    }

    public function test_new_book_starts_with_every_copy_available(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/library/books', [
                'title' => 'The Joys of Motherhood',
                'author' => 'Buchi Emecheta',
                'barcode' => 'BC-555',
                'total_copies' => 4,
            ])
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/library/books')
            ->assertOk()
            ->assertJsonPath('data.0.available_copies', 4)
            ->assertJsonPath('data.0.on_loan', 0);
    }

    // ------------------------------------------------------------------- hostel

    public function test_room_list_counts_real_occupancy(): void
    {
        $this->inSchool($this->school->id, function () {
            // The hostel model behind the original endpoints (HostelService).
            $roomId = $this->makeRoom(4);
            $bed = \App\Models\HostelBed::where('room_id', $roomId)->where('label', 'B1')->firstOrFail();
            \App\Models\HostelAllocation::create([
                'school_id' => $this->school->id, 'student_id' => $this->student->id, 'bed_id' => $bed->id,
                'starts_on' => now()->toDateString(), 'status' => 'active',
            ]);

            $this->actingAs($this->admin, 'sanctum')
                ->getJson('/api/v1/hostel/rooms')
                ->assertOk()
                ->assertJsonPath('data.0.hostel_name', 'Unity House')
                ->assertJsonPath('data.0.capacity', 4)
                ->assertJsonPath('data.0.occupied', 1)
                ->assertJsonPath('data.0.beds_free', 3)
                ->assertJsonPath('data.0.is_full', false);
        });
    }

    public function test_a_bed_cannot_be_assigned_twice(): void
    {
        $roomId = $this->makeRoom(4);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/hostel/assign-bed', [
                'room_id' => $roomId,
                'student_id' => $this->student->id,
                'bed_number' => 'B1',
            ])
            ->assertCreated();

        $second = $this->makeStudent('Amina Bello', 'GRN-002');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/hostel/assign-bed', [
                'room_id' => $roomId,
                'student_id' => $second->id,
                'bed_number' => 'B1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bed_number');
    }

    public function test_a_student_cannot_hold_two_beds(): void
    {
        $roomId = $this->makeRoom(4);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/hostel/assign-bed', [
                'room_id' => $roomId, 'student_id' => $this->student->id, 'bed_number' => 'B1',
            ])->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/hostel/assign-bed', [
                'room_id' => $roomId, 'student_id' => $this->student->id, 'bed_number' => 'B2',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_id');
    }

    public function test_a_full_room_is_refused(): void
    {
        $roomId = $this->makeRoom(1);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/hostel/assign-bed', [
                'room_id' => $roomId, 'student_id' => $this->student->id, 'bed_number' => 'B1',
            ])->assertCreated();

        $second = $this->makeStudent('Tunde Bakare', 'GRN-003');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/hostel/assign-bed', [
                'room_id' => $roomId, 'student_id' => $second->id, 'bed_number' => 'B2',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('room_id');
    }

    /**
     * `bed_assignments` carries no `school_id`, so nothing downstream would ever
     * have noticed this.
     */
    public function test_a_student_cannot_be_placed_in_another_schools_room(): void
    {
        $rival = \App\Models\Hostel::create(['school_id' => $this->otherSchool->id, 'name' => 'Rival House', 'status' => 'active']);
        $foreignRoom = \App\Models\HostelRoom::create(['school_id' => $this->otherSchool->id, 'hostel_id' => $rival->id, 'name' => 'RM-001', 'capacity' => 4, 'status' => 'available'])->id;

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/hostel/assign-bed', [
                'room_id' => $foreignRoom,
                'student_id' => $this->student->id,
                'bed_number' => 'B1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('room_id');
    }

    public function test_releasing_a_bed_frees_it(): void
    {
        $roomId = $this->makeRoom(2);

        $created = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/hostel/assign-bed', [
                'room_id' => $roomId, 'student_id' => $this->student->id, 'bed_number' => 'B1',
            ])->assertCreated()->json('id');

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/hostel/bed-assignments/{$created}")
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/hostel/rooms')
            ->assertOk()
            ->assertJsonPath('data.0.occupied', 0);
    }

    public function test_bed_assignment_list_names_the_occupant(): void
    {
        $roomId = $this->makeRoom(2);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/hostel/assign-bed', [
                'room_id' => $roomId, 'student_id' => $this->student->id, 'bed_number' => 'B1',
            ])->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/hostel/bed-assignments')
            ->assertOk()
            ->assertJsonPath('data.0.student_name', 'Chidi Nnamdi')
            ->assertJsonPath('data.0.admission_number', 'GRN-001');
    }

    // ------------------------------------------------------------------- clinic

    public function test_clinic_log_lists_visits_newest_first(): void
    {
        ClinicVisit::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'symptoms' => 'Mild fever',
            'treatment' => 'Paracetamol administered',
            'attending_nurse' => 'Nurse Grace',
            'visited_at' => now()->subDay(),
        ]);

        ClinicVisit::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'symptoms' => 'Scraped elbow',
            'treatment' => 'Cleaned and bandaged',
            'attending_nurse' => 'Nurse Grace',
            'visited_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/health/clinic-visits')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.symptoms', 'Scraped elbow')
            ->assertJsonPath('data.0.student_name', 'Chidi Nnamdi');
    }

    /**
     * Health data minimisation (doc §12): the log says who was seen and what
     * was done, and carries none of the student's stored medical record.
     */
    public function test_clinic_log_does_not_expose_the_stored_medical_record(): void
    {
        $this->student->update([
            'blood_group' => 'O+',
            'medical_notes' => 'Asthmatic',
        ]);

        ClinicVisit::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'symptoms' => 'Headache',
            'treatment' => 'Rest',
            'attending_nurse' => 'Nurse Grace',
            'visited_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/health/clinic-visits')
            ->assertOk();

        $response->assertJsonMissingPath('data.0.blood_group');
        $this->assertStringNotContainsString('Asthmatic', $response->getContent());
        $this->assertStringNotContainsString('O+', $response->getContent());
    }

    public function test_a_teacher_cannot_read_the_clinic_log(): void
    {
        $teacher = User::create([
            'name' => 'Mr Chidi',
            'email' => 'teacher@greenfield.test',
            'password' => bcrypt('password'),
        ]);
        $teacher->userProfile()->create([
            'school_id' => $this->school->id,
            'role' => 'teacher',
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/health/clinic-visits')
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ helpers

    private function makeRoom(int $capacity): int
    {
        $hostel = \App\Models\Hostel::allTenants()->firstOrCreate(['school_id' => $this->school->id, 'name' => 'Unity House'], ['status' => 'active']);

        return app(\App\Services\Hostel\HostelService::class)
            ->createRoom($hostel, ['name' => 'RM-'.fake()->unique()->numberBetween(100, 999), 'capacity' => $capacity])->id;
    }

    private function makeStudent(string $name, string $admissionNumber): Student
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@greenfield.test',
            'password' => bcrypt('password'),
        ]);

        return Student::create([
            'school_id' => $this->school->id,
            'user_id' => $user->id,
            'admission_number' => $admissionNumber,
            'status' => 'active',
        ]);
    }
}
