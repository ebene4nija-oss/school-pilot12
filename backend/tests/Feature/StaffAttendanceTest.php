<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\School;
use App\Models\Term;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Staff attendance for the admin, and the clock-in location a school sets so
 * GPS clock-in works at all (it fails closed without one).
 */
class StaffAttendanceTest extends TestCase
{
    use RefreshDatabase;

    // Ikeja, Lagos.
    private const LAT = 6.6018;
    private const LNG = 3.3515;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function url(string $path, string $subdomain = 'hilltop'): string
    {
        return "http://{$subdomain}.localhost{$path}";
    }

    private function makeSchool(string $sub = 'hilltop', array $overrides = []): School
    {
        return School::create(array_merge(['name' => ucfirst($sub), 'slug' => $sub, 'subdomain' => $sub], $overrides));
    }

    private function makeUser(School $school, string $role, string $name = 'Someone'): array
    {
        $user = User::factory()->create(['name' => $name]);
        UserProfile::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $user->createToken('t')->plainTextToken];
    }

    private function makeStaff(School $school, User $user, ?string $employed = null): int
    {
        return DB::table('staff')->insertGetId([
            'school_id' => $school->id, 'user_id' => $user->id, 'designation' => 'Class Teacher',
            'employment_date' => $employed, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeTerm(School $school): Term
    {
        $session = AcademicSession::create(['school_id' => $school->id, 'name' => '2026/2027', 'start_date' => now(), 'end_date' => now()->addYear()]);

        return Term::create(['school_id' => $school->id, 'session_id' => $session->id, 'name' => 'First Term', 'start_date' => now(), 'end_date' => now()->addMonths(3)]);
    }

    /** A clock-in row as the app writes it; $wat is Nigerian local time. */
    private function clockIn(School $school, Term $term, int $staffId, string $wat, string $status = 'present'): void
    {
        $at = Carbon::parse($wat, 'Africa/Lagos');
        DB::table('attendance_records')->insert([
            'school_id' => $school->id, 'term_id' => $term->id, 'staff_id' => $staffId,
            'date' => $at->toDateString(), 'status' => $status,
            'created_at' => $at->copy()->setTimezone('UTC'), 'updated_at' => $at->copy()->setTimezone('UTC'),
        ]);
    }

    private function as(string $token)
    {
        // The guard remembers the first user it resolved; switch users cleanly.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    // ----------------------------------------------------------- location

    public function test_admin_sets_the_location_and_staff_can_then_clock_in()
    {
        $school = $this->makeSchool();
        [, $admin] = $this->makeUser($school, 'school_admin');
        [$teacherUser, $teacher] = $this->makeUser($school, 'teacher');
        $this->makeStaff($school, $teacherUser);
        $term = $this->makeTerm($school);
        $nearby = ['term_id' => $term->id, 'latitude' => 6.60225, 'longitude' => 3.3515];

        // Fails closed before the school has a location.
        $this->as($teacher)->postJson($this->url('/api/v1/attendance/staff-gps'), $nearby)->assertStatus(422);

        $this->as($admin)->putJson($this->url('/api/v1/attendance/clock-in-location'), [
            'latitude' => self::LAT, 'longitude' => self::LNG, 'radius_metres' => 200, 'staff_resume_time' => '07:45',
        ])->assertOk()->assertJson(['configured' => true, 'radius_metres' => 200, 'staff_resume_time' => '07:45']);

        $this->as($admin)->getJson($this->url('/api/v1/attendance/clock-in-location'))
            ->assertOk()->assertJson(['latitude' => self::LAT, 'longitude' => self::LNG]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'school.clock_in_location.updated', 'school_id' => $school->id]);

        $this->as($teacher)->postJson($this->url('/api/v1/attendance/staff-gps'), $nearby)->assertStatus(201);
    }

    public function test_location_rejects_out_of_range_radius_and_a_zero_fix()
    {
        $school = $this->makeSchool();
        [, $admin] = $this->makeUser($school, 'school_admin');
        $base = ['latitude' => self::LAT, 'longitude' => self::LNG, 'radius_metres' => 200, 'staff_resume_time' => '07:30'];

        $this->as($admin)->putJson($this->url('/api/v1/attendance/clock-in-location'), ['radius_metres' => 20] + $base)
            ->assertStatus(422)->assertJsonValidationErrors('radius_metres');
        $this->as($admin)->putJson($this->url('/api/v1/attendance/clock-in-location'), ['radius_metres' => 5000] + $base)
            ->assertStatus(422);
        $this->as($admin)->putJson($this->url('/api/v1/attendance/clock-in-location'), ['staff_resume_time' => '7.30am'] + $base)
            ->assertStatus(422);
        $this->as($admin)->putJson($this->url('/api/v1/attendance/clock-in-location'), ['latitude' => 0, 'longitude' => 0] + $base)
            ->assertStatus(422);

        $this->assertNull($school->fresh()->latitude);
    }

    public function test_test_from_here_reports_distance_without_recording_anything()
    {
        $school = $this->makeSchool('hilltop', ['latitude' => self::LAT, 'longitude' => self::LNG, 'geofence_radius_meters' => 200]);
        [, $admin] = $this->makeUser($school, 'school_admin');

        $this->as($admin)->postJson($this->url('/api/v1/attendance/clock-in-location/test'), ['latitude' => 6.60225, 'longitude' => 3.3515])
            ->assertOk()->assertJson(['configured' => true, 'within' => true, 'radius_metres' => 200]);

        // Abuja.
        $this->as($admin)->postJson($this->url('/api/v1/attendance/clock-in-location/test'), ['latitude' => 9.0765, 'longitude' => 7.3986])
            ->assertOk()->assertJson(['within' => false]);

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_teachers_cannot_set_the_location_or_see_staff_attendance()
    {
        $school = $this->makeSchool();
        [, $teacher] = $this->makeUser($school, 'teacher');

        $this->as($teacher)->putJson($this->url('/api/v1/attendance/clock-in-location'), [
            'latitude' => 9.0765, 'longitude' => 7.3986, 'radius_metres' => 1000, 'staff_resume_time' => '10:00',
        ])->assertStatus(403);
        $this->as($teacher)->getJson($this->url('/api/v1/attendance/staff'))->assertStatus(403);
        $this->as($teacher)->getJson($this->url('/api/v1/attendance/clock-in-location'))->assertStatus(403);
    }

    // ------------------------------------------------------------ late

    public function test_clock_in_after_resume_time_is_recorded_late_in_nigerian_time()
    {
        $school = $this->makeSchool('hilltop', ['latitude' => self::LAT, 'longitude' => self::LNG, 'staff_resume_time' => '07:30:00']);
        [$teacherUser, $teacher] = $this->makeUser($school, 'teacher');
        $this->makeStaff($school, $teacherUser);
        $term = $this->makeTerm($school);
        $body = ['term_id' => $term->id, 'latitude' => 6.60225, 'longitude' => 3.3515];

        // 07:20 WAT is 06:20 UTC: on time, even though the server clock says 06:20.
        Carbon::setTestNow(Carbon::parse('2026-10-07 07:20', 'Africa/Lagos'));
        $this->as($teacher)->postJson($this->url('/api/v1/attendance/staff-gps'), $body)
            ->assertStatus(201)->assertJson(['late' => false]);

        Carbon::setTestNow(Carbon::parse('2026-10-08 07:41', 'Africa/Lagos'));
        $this->as($teacher)->postJson($this->url('/api/v1/attendance/staff-gps'), $body)
            ->assertStatus(201)->assertJson(['late' => true]);

        $this->assertDatabaseHas('attendance_records', ['date' => '2026-10-07', 'status' => 'present']);
        $this->assertDatabaseHas('attendance_records', ['date' => '2026-10-08', 'status' => 'late']);
    }

    // ------------------------------------------------------------ report

    public function test_staff_report_counts_in_late_absent_leave_and_holiday()
    {
        // Wednesday 7 Oct 2026, 10:00 WAT. Range Mon 5 – Wed 7.
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00', 'Africa/Lagos'));

        $school = $this->makeSchool();
        [, $admin] = $this->makeUser($school, 'school_admin');
        $term = $this->makeTerm($school);

        [$ada] = $this->makeUser($school, 'teacher', 'Ada Obi');
        [$bayo] = $this->makeUser($school, 'teacher', 'Bayo Ade');
        [$chi] = $this->makeUser($school, 'teacher', 'Chi Eze');
        $adaId = $this->makeStaff($school, $ada);
        $bayoId = $this->makeStaff($school, $bayo);
        $chiId = $this->makeStaff($school, $chi, '2026-10-07'); // started Wednesday

        // Tuesday is a public holiday.
        DB::table('school_calendar_events')->insert([
            'school_id' => $school->id, 'title' => 'Holiday', 'category' => 'holiday',
            'start_date' => '2026-10-06', 'end_date' => '2026-10-06', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->clockIn($school, $term, $adaId, '2026-10-05 07:10');
        $this->clockIn($school, $term, $adaId, '2026-10-05 12:00'); // second tap, ignored
        $this->clockIn($school, $term, $adaId, '2026-10-07 08:05', 'late');

        // Bayo is on approved leave Wednesday and missed Monday.
        DB::table('leave_requests')->insert([
            'school_id' => $school->id, 'staff_id' => $bayoId, 'leave_type' => 'sick',
            'start_date' => '2026-10-07', 'end_date' => '2026-10-07', 'days_requested' => 1,
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Another school's staff never appear.
        $other = $this->makeSchool('riverside');
        [$stranger] = $this->makeUser($other, 'teacher', 'Zed Stranger');
        $this->makeStaff($other, $stranger);

        $res = $this->as($admin)->getJson($this->url('/api/v1/attendance/staff?from=2026-10-05&to=2026-10-07'))
            ->assertOk()
            ->assertJson(['school_days' => 2, 'resume_time' => '07:30', 'location_configured' => false]);

        $rows = collect($res->json('staff'))->keyBy('name');
        $this->assertCount(3, $rows);
        $this->assertArrayNotHasKey('Zed Stranger', $rows);

        $this->assertSame(['days_in' => 2, 'days_late' => 1, 'days_absent' => 0, 'days_on_leave' => 0],
            collect($rows['Ada Obi'])->only('days_in', 'days_late', 'days_absent', 'days_on_leave')->all());
        $this->assertSame(['days_in' => 0, 'days_late' => 0, 'days_absent' => 1, 'days_on_leave' => 1],
            collect($rows['Bayo Ade'])->only('days_in', 'days_late', 'days_absent', 'days_on_leave')->all());
        // Not employed on Monday; absent Wednesday only.
        $this->assertSame(1, $rows['Chi Eze']['days_absent']);
        $this->assertStringStartsWith('2026-10-07T08:05:00', $rows['Ada Obi']['last_clock_in']);

        // One person, day by day, newest first.
        $this->as($admin)->getJson($this->url("/api/v1/attendance/staff/{$adaId}?from=2026-10-05&to=2026-10-07"))
            ->assertOk()
            ->assertJsonPath('days.0.date', '2026-10-07')
            ->assertJsonPath('days.0.status', 'late')
            ->assertJsonPath('days.1.status', 'holiday')
            ->assertJsonPath('days.2.status', 'present');
    }

    public function test_today_is_not_counted_as_missed_before_the_resume_time()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 07:20', 'Africa/Lagos'));

        $school = $this->makeSchool();
        [, $admin] = $this->makeUser($school, 'school_admin');
        $term = $this->makeTerm($school);
        [$ada] = $this->makeUser($school, 'teacher', 'Ada Obi');
        [$bayo] = $this->makeUser($school, 'teacher', 'Bayo Ade');
        $adaId = $this->makeStaff($school, $ada);
        $this->makeStaff($school, $bayo);
        $this->clockIn($school, $term, $adaId, '2026-10-07 07:10');

        $res = $this->as($admin)->getJson($this->url('/api/v1/attendance/staff?from=2026-10-07&to=2026-10-07'))
            ->assertOk()->assertJson(['school_days' => 0]);

        $rows = collect($res->json('staff'))->keyBy('name');
        // An early arrival shows as in; nobody is absent yet.
        $this->assertSame(1, $rows['Ada Obi']['days_in']);
        $this->assertSame(0, $rows['Bayo Ade']['days_absent']);
    }

    public function test_another_schools_staff_member_is_not_found()
    {
        $school = $this->makeSchool();
        [, $admin] = $this->makeUser($school, 'school_admin');
        $other = $this->makeSchool('riverside');
        [$stranger] = $this->makeUser($other, 'teacher');
        $strangerId = $this->makeStaff($other, $stranger);

        $this->as($admin)->getJson($this->url("/api/v1/attendance/staff/{$strangerId}"))->assertStatus(404);
    }

    public function test_range_is_bounded()
    {
        $school = $this->makeSchool();
        [, $admin] = $this->makeUser($school, 'school_admin');

        $this->as($admin)->getJson($this->url('/api/v1/attendance/staff?from=2025-01-01&to=2025-12-31'))->assertStatus(422);
        $this->as($admin)->getJson($this->url('/api/v1/attendance/staff?from=2025-03-10&to=2025-03-01'))->assertStatus(422);
    }
}
