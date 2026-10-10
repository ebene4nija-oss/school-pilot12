<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\SendAbsenceAlertsJob;
use Illuminate\Support\Carbon;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\Student;
use App\Services\GeofenceService;
use App\Services\QrAttendanceTokenService;
use App\Support\StaffDay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function __construct(
        private QrAttendanceTokenService $qrTokens,
        private GeofenceService $geofence,
    ) {
    }

    public function generateQrToken(Request $request)
    {
        $user = $request->user();
        $schoolId = $this->schoolId();

        if (! $schoolId) {
            return response()->json(['message' => 'No school context for this user.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'class_id' => 'required|exists:classes,id',
            'term_id' => 'required|exists:terms,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        return response()->json([
            'qr_token' => $this->qrTokens->issue($schoolId, (int) $request->class_id, (int) $request->term_id),
            'expires_in_seconds' => QrAttendanceTokenService::TTL_SECONDS,
        ]);
    }

    /**
     * Mark a whole register in one request.
     *
     * The single-student endpoint is the QR flow: one scan, one token, one
     * child. Roll call is the other half of §7.5 ("QR code scan ... or manual
     * entry") and it was only reachable by calling that endpoint once per
     * child — 42 sequential HTTP requests from a teacher's phone on 3G, each
     * able to fail halfway and leave the register half-marked. This is the
     * most-used endpoint in the product and it was shaped for a demo.
     *
     * Three properties it needs to survive a real classroom:
     *
     * - **Idempotent.** A teacher on a bad connection taps "save" twice, or the
     *   client retries a request whose response never arrived. The same
     *   idempotency key returns the first result without writing again.
     * - **Atomic.** Either the register is marked or it is not; a partial
     *   register is worse than none, because the teacher cannot tell which.
     * - **Per-row results.** A class list drifts — a transferred pupil, a
     *   stale cached roster — so unknown ids are reported rather than
     *   collapsing the whole request into one 422.
     */
    public function markBulkAttendance(Request $request)
    {
        $user = $request->user();
        $schoolId = $this->schoolId();

        $validated = $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'date' => 'required|date',
            'records' => 'required|array|min:1|max:200',
            'records.*.student_id' => 'required|integer',
            'records.*.status' => 'required|in:present,absent,late,excused',
            'idempotency_key' => 'required|string|min:8|max:100',
            // Needed only to change a mark on a past day (admins only).
            'correction_reason' => 'nullable|string|min:3|max:255',
        ]);

        // Scoped per school so two schools cannot collide on a client-generated
        // key, and so one school cannot probe another's.
        $cacheKey = "attendance:bulk:{$schoolId}:" . sha1($validated['idempotency_key']);

        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached + ['idempotent_replay' => true]);
        }

        $submitted = collect($validated['records'])->keyBy('student_id');

        // One query for the whole register rather than a lookup per row.
        $known = Student::where('school_id', $schoolId)
            ->whereIn('id', $submitted->keys())
            ->pluck('id')
            ->flip();

        $marked = [];
        $rejected = [];

        DB::transaction(function () use ($submitted, $known, $schoolId, $validated, $user, &$marked, &$rejected) {
            foreach ($submitted as $studentId => $row) {
                if (! $known->has($studentId)) {
                    $rejected[] = [
                        'student_id' => (int) $studentId,
                        'reason' => 'Not a student at this school.',
                    ];
                    continue;
                }

                $refusal = $this->record($schoolId, (int) $studentId, $validated['date'], (int) $validated['term_id'], $row['status'], $user, $validated['correction_reason'] ?? null);
                if ($refusal) {
                    $rejected[] = ['student_id' => (int) $studentId, 'reason' => $refusal];
                    continue;
                }

                $marked[] = ['student_id' => (int) $studentId, 'status' => $row['status']];
            }
        });

        // Parents who asked to be told, if the school has switched alerts on.
        $absent = collect($marked)->where('status', 'absent')->pluck('student_id')->all();

        if ($absent !== []) {
            SendAbsenceAlertsJob::dispatch($schoolId, $absent, Carbon::parse($validated['date'])->toDateString());
        }

        $payload = [
            'message' => sprintf('Marked %d student(s).', count($marked)),
            'date' => $validated['date'],
            'marked_count' => count($marked),
            'rejected_count' => count($rejected),
            'marked' => $marked,
            'rejected' => $rejected,
        ];

        // Long enough to cover a retry after a lost response, short enough that
        // tomorrow's register with a reused key is not silently swallowed.
        Cache::put($cacheKey, $payload, now()->addHours(12));

        return response()->json($payload);
    }

    /**
     * Today's register for a class, so a teacher opens the screen with what
     * was already marked rather than a blank list they might double-enter.
     */
    public function classRegister(Request $request)
    {
        $user = $request->user();
        $schoolId = $this->schoolId();

        $validated = $request->validate([
            'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'date' => 'nullable|date',
        ]);

        $date = $validated['date'] ?? now()->toDateString();

        $students = Student::where('school_id', $schoolId)
            ->where('class_id', $validated['class_id'])
            ->where('status', 'active')
            ->with('user:id,name')
            ->orderBy('admission_number')
            ->get();

        $existing = AttendanceRecord::where('school_id', $schoolId)
            ->whereIn('student_id', $students->pluck('id'))
            ->where('date', $date)
            ->get()
            ->keyBy('student_id');

        return response()->json([
            'date' => $date,
            'class_id' => (int) $validated['class_id'],
            'register' => $students->map(fn (Student $student) => [
                'student_id' => $student->id,
                'name' => $student->user?->name,
                'admission_number' => $student->admission_number,
                'status' => $existing->get($student->id)?->status,
                'already_marked' => $existing->has($student->id),
            ])->values(),
        ]);
    }

    public function markStudentAttendance(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'student_id' => 'required|exists:students,id',
            'term_id' => 'required|exists:terms,id',
            'date' => 'required|date',
            'status' => 'required|in:present,absent,late,excused',
            'qr_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $this->schoolId();

        $claims = $this->qrTokens->verify($request->qr_token);

        // A single rejection reason for every failure mode: an attacker probing
        // tokens learns nothing about which part was wrong.
        if ($claims === null) {
            return response()->json([
                'message' => 'QR token is invalid, expired, or has already been used.',
            ], 422);
        }

        if ((int) $claims['school_id'] !== (int) $schoolId) {
            return response()->json([
                'message' => 'QR token is invalid, expired, or has already been used.',
            ], 422);
        }

        if ((int) $claims['term_id'] !== (int) $request->term_id) {
            return response()->json([
                'message' => 'QR token does not match the submitted term.',
            ], 422);
        }

        $refusal = $this->record($schoolId, (int) $request->student_id, $request->date, (int) $request->term_id, $request->status, $user, null);
        if ($refusal) {
            return response()->json(['message' => $refusal], 403);
        }
        $record = AttendanceRecord::where('school_id', $schoolId)->where('student_id', $request->student_id)->whereDate('date', $request->date)->first();

        if ($request->status === 'absent') {
            SendAbsenceAlertsJob::dispatch($schoolId, [(int) $request->student_id], Carbon::parse($request->date)->toDateString());
        }

        return response()->json([
            'message' => 'Student attendance marked successfully',
            'attendance' => $record,
        ]);
    }

    /**
     * Write one mark. A first mark, or a change on the same day, goes
     * straight in; a change to a past day is a correction, which only an
     * administrator makes, with a reason (TASK 12). Every change of status
     * is kept in attendance_corrections.
     *
     * @return string|null why it was refused
     */
    private function record(int $schoolId, int $studentId, string $date, int $termId, string $status, $user, ?string $reason): ?string
    {
        $existing = AttendanceRecord::where('school_id', $schoolId)->where('student_id', $studentId)->whereDate('date', $date)->first();

        if (! $existing) {
            AttendanceRecord::create(['school_id' => $schoolId, 'student_id' => $studentId, 'date' => $date, 'term_id' => $termId, 'status' => $status]);

            return null;
        }
        if ($existing->status === $status) {
            return null;
        }

        $pastDay = Carbon::parse($date)->lt(Carbon::today());
        if ($pastDay) {
            if (! in_array($user->userProfile?->role, ['school_admin', 'super_admin'], true)) {
                return 'Only an administrator can change attendance for a past day.';
            }
            if (! $reason) {
                return 'Give a reason to correct attendance for a past day.';
            }
        }

        DB::table('attendance_corrections')->insert([
            'school_id' => $schoolId, 'attendance_record_id' => $existing->id, 'student_id' => $studentId, 'date' => Carbon::parse($date)->toDateString(),
            'from' => $existing->status, 'to' => $status, 'reason' => $reason, 'user_id' => $user->id, 'created_at' => now(),
        ]);
        $existing->update(['status' => $status, 'term_id' => $termId]);

        return null;
    }

    /** A student's (or a class's) correction history. */
    public function corrections(Request $request)
    {
        $schoolId = $this->schoolId();

        return response()->json(['data' => DB::table('attendance_corrections as c')->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('students as s', 's.id', '=', 'c.student_id')->leftJoin('users as su', 'su.id', '=', 's.user_id')
            ->where('c.school_id', $schoolId)
            ->when($request->filled('student_id'), fn ($q) => $q->where('c.student_id', $request->integer('student_id')))
            ->when($request->filled('class_id'), fn ($q) => $q->where('s.class_id', $request->integer('class_id')))
            ->orderByDesc('c.id')->limit(300)
            ->get(['c.date', 'c.from', 'c.to', 'c.reason', 'c.created_at', 'u.name as by', 'su.name as student'])]);
    }

    public function staffGpsClockIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'staff_id' => 'nullable|integer',
            'term_id' => 'required|exists:terms,id',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $this->schoolId();
        $school = $schoolId ? School::find($schoolId) : null;

        if (! $school) {
            return response()->json(['message' => 'No school context for this user.'], 403);
        }

        /*
         * Whose clock-in this is. A phone at the gate proves where *that
         * phone* is, so a teacher clocks in themselves only — otherwise one
         * person on the premises could mark an absent colleague present,
         * which is the thing GPS clock-in exists to stop. An administrator
         * may still record one for a named member of staff.
         */
        $isAdmin = in_array($user->userProfile?->role, ['school_admin', 'super_admin'], true);
        $own = DB::table('staff')->where('school_id', $schoolId)->where('user_id', $user->id)->value('id');
        $staffId = $request->filled('staff_id') ? (int) $request->staff_id : $own;

        if (! $staffId || ! DB::table('staff')->where('id', $staffId)->where('school_id', $schoolId)->exists()) {
            return response()->json(['message' => 'Staff member not found in this school.'], 404);
        }
        if (! $isAdmin && (int) $staffId !== (int) $own) {
            return response()->json(['message' => 'You can clock in only yourself.'], 403);
        }

        $result = $this->geofence->check($school, (float) $request->latitude, (float) $request->longitude);

        // Fail closed: without coordinates on file the clock-in is unverifiable,
        // and an unverifiable "present" is exactly what this feature exists to
        // prevent. An admin sets the school's coordinates once.
        if (! $result['configured']) {
            return response()->json([
                'message' => 'This school has no gate coordinates configured, so GPS clock-in cannot be verified. An administrator must set them first.',
            ], 422);
        }

        if (! $result['within']) {
            return response()->json([
                'message' => 'You appear to be outside the school premises, so this clock-in was not recorded.',
                'distance_metres' => $result['distance'],
                'allowed_radius_metres' => $result['radius'],
            ], 422);
        }

        // Day and lateness are settled now, in school time, so changing the
        // resume time later does not rewrite past days.
        $at = StaffDay::now();
        $late = StaffDay::isLate($school, $at);

        $record = AttendanceRecord::create([
            'school_id' => $schoolId,
            'staff_id' => $staffId,
            'term_id' => $request->term_id,
            'date' => $at->toDateString(),
            'status' => $late ? 'late' : 'present',
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $user->id,
            'action' => 'staff.gps_clockin',
            'auditable_type' => AttendanceRecord::class,
            'auditable_id' => $record->id,
            // Distance is retained so an admin can review borderline clock-ins
            // rather than only seeing a pass/fail verdict.
            'new_values' => [
                'lat' => $request->latitude,
                'lng' => $request->longitude,
                'distance_metres' => $result['distance'],
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => $late
                ? 'Clock-in recorded. You are after the resume time of '.StaffDay::resumeTime($school).', so this counts as late.'
                : 'Staff GPS clock-in recorded successfully',
            'late' => $late,
            'distance_metres' => $result['distance'],
            'record' => $record,
        ], 201);
    }
}
