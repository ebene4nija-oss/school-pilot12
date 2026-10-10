<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;
use App\Services\GeofenceService;
use App\Support\StaffDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff attendance for the administrator, and the settings that make a GPS
 * clock-in mean something: where the school is, how far from that point
 * counts as "on the premises", and when staff are expected in.
 *
 * Without coordinates on file every clock-in is refused (fail closed, see
 * AttendanceController::staffGpsClockIn), so this is the screen a school
 * must visit before its staff can clock in at all.
 */
class StaffAttendanceController extends Controller
{
    /** A term and a bit — wide enough for a term report, small enough to stay cheap. */
    private const MAX_RANGE_DAYS = 120;

    public function __construct(private GeofenceService $geofence)
    {
    }

    // ------------------------------------------------------------------
    // Clock-in location
    // ------------------------------------------------------------------

    public function location(): JsonResponse
    {
        $school = $this->school();

        return response()->json($this->describeLocation($school));
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $school = $this->school();

        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius_metres' => 'required|integer|between:50,1000',
            'staff_resume_time' => ['required', 'date_format:H:i'],
        ]);

        // (0, 0) is in the Gulf of Guinea: what an unset form or a phone with
        // no fix sends, never a school.
        if ((float) $validated['latitude'] === 0.0 && (float) $validated['longitude'] === 0.0) {
            return response()->json(['errors' => ['latitude' => ['That location is 0, 0 — the phone had no GPS fix. Try again outdoors.']]], 422);
        }

        $before = $this->describeLocation($school);

        $school->forceFill([
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'geofence_radius_meters' => $validated['radius_metres'],
            'staff_resume_time' => $validated['staff_resume_time'].':00',
        ])->save();

        $after = $this->describeLocation($school->fresh());

        AuditLog::create([
            'school_id' => $school->id,
            'user_id' => $request->user()->id,
            'action' => 'school.clock_in_location.updated',
            'auditable_type' => School::class,
            'auditable_id' => $school->id,
            'old_values' => $before,
            'new_values' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'Clock-in location saved.'] + $after);
    }

    /**
     * "Would a clock-in from here pass?" — so an admin can walk the compound
     * and check the radius reaches the staff room. Nothing is recorded.
     */
    public function testLocation(Request $request): JsonResponse
    {
        $school = $this->school();

        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $result = $this->geofence->check($school, (float) $validated['latitude'], (float) $validated['longitude']);

        return response()->json([
            'configured' => $result['configured'],
            'within' => $result['within'],
            'distance_metres' => $result['distance'],
            'radius_metres' => $result['radius'],
        ]);
    }

    // ------------------------------------------------------------------
    // Staff attendance
    // ------------------------------------------------------------------

    /** Every member of staff over a date range: days in, late, missed, on leave. */
    public function index(Request $request): JsonResponse
    {
        $school = $this->school();
        [$from, $to] = $this->range($request);

        $staff = $this->staffQuery($school->id)->get();
        $days = $this->schoolDays($school, $from, $to);
        $clockIns = $this->clockIns($school->id, $from, $to);
        $leave = $this->approvedLeave($school->id, $from, $to);
        $pending = $this->pendingDay($school);

        $rows = $staff->map(function ($s) use ($days, $clockIns, $leave, $pending) {
            $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'on_leave' => 0];
            $last = null;

            foreach ($days as $day => $isHoliday) {
                $status = $this->dayStatus($s, $day, $isHoliday, $clockIns, $leave, $pending);
                if (isset($counts[$status])) {
                    $counts[$status]++;
                }
                if (isset($clockIns[$s->id][$day])) {
                    $last = $clockIns[$s->id][$day]['at'];
                }
            }

            return [
                'staff_id' => (int) $s->id,
                'name' => $s->name,
                'designation' => $s->designation,
                // "present" here means came in, on time or not.
                'days_in' => $counts['present'] + $counts['late'],
                'days_late' => $counts['late'],
                'days_absent' => $counts['absent'],
                'days_on_leave' => $counts['on_leave'],
                'last_clock_in' => $last,
            ];
        })->values();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'resume_time' => StaffDay::resumeTime($school),
            'location_configured' => $school->latitude !== null && $school->longitude !== null,
            // Days anyone could have missed: not holidays, not today before resumption.
            'school_days' => collect($days)->reject(fn ($h, $day) => $h || $day === $pending)->count(),
            'summary' => [
                'staff' => $rows->count(),
                'days_in' => $rows->sum('days_in'),
                'days_late' => $rows->sum('days_late'),
                'days_absent' => $rows->sum('days_absent'),
            ],
            'staff' => $rows,
        ]);
    }

    /** One member of staff, day by day. */
    public function show(Request $request, int $staffId): JsonResponse
    {
        $school = $this->school();
        [$from, $to] = $this->range($request);

        $member = $this->staffQuery($school->id)->where('st.id', $staffId)->first();
        abort_unless($member, 404, 'Staff member not found in this school.');

        $days = $this->schoolDays($school, $from, $to);
        $clockIns = $this->clockIns($school->id, $from, $to, $staffId);
        $leave = $this->approvedLeave($school->id, $from, $to, $staffId);
        $pending = $this->pendingDay($school);

        $rows = collect($days)->map(fn ($isHoliday, $day) => [
            'date' => $day,
            'status' => $this->dayStatus($member, $day, $isHoliday, $clockIns, $leave, $pending),
            'clocked_in_at' => $clockIns[$member->id][$day]['at'] ?? null,
        ])->reverse()->values();

        return response()->json([
            'staff_id' => (int) $member->id,
            'name' => $member->name,
            'designation' => $member->designation,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'resume_time' => StaffDay::resumeTime($school),
            'days' => $rows,
        ]);
    }

    // ------------------------------------------------------------------

    private function school(): School
    {
        $school = School::find($this->schoolId());
        abort_unless($school, 409, 'Choose a school first.');

        return $school;
    }

    private function describeLocation(School $school): array
    {
        return [
            'configured' => $school->latitude !== null && $school->longitude !== null,
            'latitude' => $school->latitude !== null ? (float) $school->latitude : null,
            'longitude' => $school->longitude !== null ? (float) $school->longitude : null,
            'radius_metres' => (int) ($school->geofence_radius_meters ?: 250),
            'staff_resume_time' => StaffDay::resumeTime($school),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} in school time; defaults to this week so far. */
    private function range(Request $request): array
    {
        $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $today = StaffDay::now()->startOfDay();
        $to = $request->filled('to') ? Carbon::parse($request->input('to'), StaffDay::TIMEZONE) : $today->copy();
        $from = $request->filled('from') ? Carbon::parse($request->input('from'), StaffDay::TIMEZONE) : $today->copy()->startOfWeek();

        // Days that have not happened yet cannot be missed.
        if ($to->gt($today)) {
            $to = $today->copy();
        }

        if ($from->gt($to)) {
            abort(response()->json(['message' => 'The start date is after the end date.'], 422));
        }

        if ($from->diffInDays($to, true) >= self::MAX_RANGE_DAYS) {
            abort(response()->json(['message' => 'Choose a range of '.self::MAX_RANGE_DAYS.' days or fewer.'], 422));
        }

        return [$from, $to];
    }

    private function staffQuery(int $schoolId)
    {
        return DB::table('staff as st')
            ->join('users as u', 'u.id', '=', 'st.user_id')
            ->where('st.school_id', $schoolId)
            ->whereNull('st.deleted_at')
            ->orderBy('u.name')
            ->select(['st.id', 'u.name', 'st.designation', 'st.employment_date']);
    }

    /**
     * Weekdays in the range, keyed by date, true when the school calendar
     * marks it a holiday.
     *
     * @return array<string, bool>
     */
    private function schoolDays(School $school, Carbon $from, Carbon $to): array
    {
        $holidays = DB::table('school_calendar_events')
            ->where('school_id', $school->id)
            ->where('category', 'holiday')
            ->where('start_date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->where('end_date', '>=', $from->toDateString())
                ->orWhere(fn ($q) => $q->whereNull('end_date')->where('start_date', '>=', $from->toDateString())))
            ->get(['start_date', 'end_date']);

        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $date = $d->toDateString();
            if ($d->isWeekend()) {
                continue;
            }
            $days[$date] = $holidays->contains(function ($h) use ($date) {
                $start = substr((string) $h->start_date, 0, 10);
                $end = substr((string) ($h->end_date ?? $h->start_date), 0, 10);

                return $date >= $start && $date <= $end;
            });
        }

        return $days;
    }

    /**
     * First clock-in per staff member per day.
     *
     * @return array<int, array<string, array{at: string, late: bool}>>
     */
    private function clockIns(int $schoolId, Carbon $from, Carbon $to, ?int $staffId = null): array
    {
        $rows = DB::table('attendance_records')
            ->where('school_id', $schoolId)
            ->whereNotNull('staff_id')
            ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('created_at')
            ->get(['staff_id', 'date', 'status', 'created_at']);

        $out = [];
        foreach ($rows as $r) {
            $day = substr((string) $r->date, 0, 10);
            if (isset($out[$r->staff_id][$day])) {
                continue;
            }
            $out[$r->staff_id][$day] = [
                'at' => Carbon::parse($r->created_at, 'UTC')->setTimezone(StaffDay::TIMEZONE)->toIso8601String(),
                'late' => $r->status === 'late',
            ];
        }

        return $out;
    }

    /** @return Collection<int, Collection> approved leave, grouped by staff id */
    private function approvedLeave(int $schoolId, Carbon $from, Carbon $to, ?int $staffId = null): Collection
    {
        return DB::table('leave_requests')
            ->where('school_id', $schoolId)
            ->where('status', 'approved')
            ->when($staffId, fn ($q) => $q->where('staff_id', $staffId))
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString())
            ->get(['staff_id', 'start_date', 'end_date'])
            ->groupBy('staff_id');
    }

    /**
     * Today, until the resume time passes. Nobody has "missed" a day at 7am,
     * but someone who clocked in at 7:10 should still show as in.
     */
    private function pendingDay(School $school): ?string
    {
        $now = StaffDay::now();

        return $now->format('H:i') > StaffDay::resumeTime($school) ? null : $now->toDateString();
    }

    /** present | late | absent | on_leave | holiday | not_employed | not_yet */
    private function dayStatus(object $member, string $day, bool $isHoliday, array $clockIns, Collection $leave, ?string $pendingDay): string
    {
        if ($clock = $clockIns[$member->id][$day] ?? null) {
            return $clock['late'] ? 'late' : 'present';
        }
        if ($day === $pendingDay) {
            return 'not_yet';
        }
        if ($isHoliday) {
            return 'holiday';
        }
        if ($member->employment_date && $day < substr((string) $member->employment_date, 0, 10)) {
            return 'not_employed';
        }
        $onLeave = ($leave->get($member->id) ?? collect())->contains(
            fn ($l) => $day >= substr((string) $l->start_date, 0, 10) && $day <= substr((string) $l->end_date, 0, 10)
        );

        return $onLeave ? 'on_leave' : 'absent';
    }
}
