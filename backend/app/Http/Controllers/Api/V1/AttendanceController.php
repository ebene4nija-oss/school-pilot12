<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\Student;
use App\Services\GeofenceService;
use App\Services\QrAttendanceTokenService;
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
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

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
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $validated = $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'date' => 'required|date',
            'records' => 'required|array|min:1|max:200',
            'records.*.student_id' => 'required|integer',
            'records.*.status' => 'required|in:present,absent,late,excused',
            'idempotency_key' => 'required|string|min:8|max:100',
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

        DB::transaction(function () use ($submitted, $known, $schoolId, $validated, &$marked, &$rejected) {
            foreach ($submitted as $studentId => $row) {
                if (! $known->has($studentId)) {
                    $rejected[] = [
                        'student_id' => (int) $studentId,
                        'reason' => 'Not a student at this school.',
                    ];
                    continue;
                }

                AttendanceRecord::updateOrCreate(
                    [
                        'school_id' => $schoolId,
                        'student_id' => $studentId,
                        'date' => $validated['date'],
                    ],
                    [
                        'term_id' => $validated['term_id'],
                        'status' => $row['status'],
                    ]
                );

                $marked[] = ['student_id' => (int) $studentId, 'status' => $row['status']];
            }
        });

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
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

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
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

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

        $record = AttendanceRecord::updateOrCreate(
            [
                'school_id' => $schoolId,
                'student_id' => $request->student_id,
                'date' => $request->date,
            ],
            [
                'term_id' => $request->term_id,
                'status' => $request->status,
            ]
        );

        return response()->json([
            'message' => 'Student attendance marked successfully',
            'attendance' => $record,
        ]);
    }

    public function staffGpsClockIn(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'staff_id' => 'required|exists:staff,id',
            'term_id' => 'required|exists:terms,id',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;
        $school = $schoolId ? School::find($schoolId) : null;

        if (! $school) {
            return response()->json(['message' => 'No school context for this user.'], 403);
        }

        // `exists:staff,id` alone would let one school clock in another school's
        // staff, since the rule is not tenant-aware.
        $staffBelongsToSchool = DB::table('staff')
            ->where('id', $request->staff_id)
            ->where('school_id', $schoolId)
            ->exists();

        if (! $staffBelongsToSchool) {
            return response()->json(['message' => 'Staff member not found in this school.'], 404);
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

        $record = AttendanceRecord::create([
            'school_id' => $schoolId,
            'staff_id' => $request->staff_id,
            'term_id' => $request->term_id,
            'date' => now()->toDateString(),
            'status' => 'present',
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
            'message' => 'Staff GPS clock-in recorded successfully',
            'distance_metres' => $result['distance'],
            'record' => $record,
        ], 201);
    }
}
