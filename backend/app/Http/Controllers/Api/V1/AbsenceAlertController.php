<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Guardian;
use App\Models\School;
use App\Models\Student;
use App\Services\SmsService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Absence alerts: the school's switch, and each parent's opt-in.
 *
 * Nothing is sent until the school switches alerts on *and* a parent opts in
 * for that child. A parent opts in from the app; a parent who cannot use the
 * app (a phone-only account) asks the school office, which records it for
 * them, on the record.
 */
class AbsenceAlertController extends Controller
{
    public function __construct(private TenantContext $tenant, private SmsService $sms)
    {
    }

    // --- School admin ---------------------------------------------------

    public function schoolSettings()
    {
        $school = School::query()->findOrFail($this->tenant->schoolId());

        return response()->json($this->settings($school));
    }

    public function updateSchool(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'sms' => 'sometimes|boolean',
        ]);

        $school = School::query()->findOrFail($this->tenant->schoolId());
        $before = ['enabled' => (bool) $school->absence_alerts_enabled, 'sms' => (bool) $school->absence_alerts_sms];

        $school->absence_alerts_enabled = $validated['enabled'];

        if (array_key_exists('sms', $validated)) {
            $school->absence_alerts_sms = $validated['sms'];
        }

        $school->save();
        $after = ['enabled' => (bool) $school->absence_alerts_enabled, 'sms' => (bool) $school->absence_alerts_sms];

        if ($before !== $after) {
            $this->audit($request, $school->id, 'absence_alerts.school_changed', School::class, $school->id, $before, $after);
        }

        return response()->json(['message' => $after['enabled']
            ? 'Absence alerts are on. Parents who opt in will be told when their child is marked absent.'
            : 'Absence alerts are off. No parent will be sent one.'] + $this->settings($school));
    }

    /** A child's parents and whether each has opted in — for the school office. */
    public function forStudent(int $studentId)
    {
        $student = Student::query()->with('user:id,name')->findOrFail($studentId);

        $guardians = Guardian::query()
            ->with('user:id,name')
            ->with('user.userProfile:id,user_id,phone')
            ->whereHas('students', fn ($q) => $q->where('students.id', $student->id))
            ->get()
            ->map(function (Guardian $guardian) use ($student) {
                $pivot = DB::table('student_guardian')
                    ->where('student_id', $student->id)
                    ->where('guardian_id', $guardian->id)
                    ->first(['absence_alerts']);

                return [
                    'guardian_id' => $guardian->id,
                    'name' => $guardian->user?->name,
                    'relationship' => $guardian->relationship,
                    'has_phone' => filled($guardian->user?->userProfile?->phone),
                    'enabled' => (bool) ($pivot->absence_alerts ?? false),
                ];
            });

        return response()->json([
            'student' => ['id' => $student->id, 'name' => $student->user?->name],
            'guardians' => $guardians,
        ]);
    }

    /** The school office recording a parent's choice, at the parent's request. */
    public function setForGuardian(Request $request, int $studentId, int $guardianId)
    {
        $validated = $request->validate(['enabled' => 'required|boolean']);

        $student = Student::query()->findOrFail($studentId);

        return $this->setPreference($request, $student, $guardianId, $validated['enabled'], 'absence_alerts.set_by_school');
    }

    // --- Parent -----------------------------------------------------------

    /** Each of my children, and whether I get absence alerts for them. */
    public function mine(Request $request)
    {
        $guardian = Guardian::query()->where('user_id', $request->user()->id)->first();
        $school = School::query()->find($this->tenant->schoolId());

        $children = $guardian
            ? DB::table('student_guardian')
                ->join('students', 'students.id', '=', 'student_guardian.student_id')
                ->join('users', 'users.id', '=', 'students.user_id')
                ->where('student_guardian.guardian_id', $guardian->id)
                ->get(['students.id as student_id', 'users.name', 'student_guardian.absence_alerts'])
                ->map(fn ($row) => [
                    'student_id' => (int) $row->student_id,
                    'name' => $row->name,
                    'enabled' => (bool) $row->absence_alerts,
                ])
            : collect();

        return response()->json([
            // Whether the school offers alerts at all; the app hides the switch if not.
            'offered' => (bool) $school?->absence_alerts_enabled,
            'channels' => $school?->absence_alerts_sms ? ['push', 'sms'] : ['push'],
            'children' => $children->values(),
        ]);
    }

    public function setMine(Request $request, int $studentId)
    {
        $validated = $request->validate(['enabled' => 'required|boolean']);

        $guardian = Guardian::query()->where('user_id', $request->user()->id)->first();
        $student = Student::query()->find($studentId);

        if (! $guardian || ! $student || ! $student->isGuardedBy($request->user())) {
            return response()->json(['message' => 'Child not found'], 404);
        }

        return $this->setPreference($request, $student, $guardian->id, $validated['enabled'], 'absence_alerts.set_by_parent');
    }

    // ------------------------------------------------------------------------

    private function setPreference(Request $request, Student $student, int $guardianId, bool $enabled, string $action)
    {
        $school = School::query()->findOrFail($student->school_id);

        if ($enabled && ! $school->absence_alerts_enabled) {
            return response()->json([
                'message' => 'The school has not switched absence alerts on.',
            ], 409);
        }

        $updated = DB::table('student_guardian')
            ->where('student_id', $student->id)
            ->where('guardian_id', $guardianId)
            ->update(['absence_alerts' => $enabled, 'absence_alerts_set_at' => now()]);

        if ($updated === 0) {
            return response()->json(['message' => 'That parent is not linked to this child.'], 404);
        }

        $this->audit($request, $student->school_id, $action, Student::class, $student->id, null, [
            'guardian_id' => $guardianId,
            'enabled' => $enabled,
        ]);

        return response()->json([
            'message' => $enabled ? 'Absence alerts are on for this child.' : 'Absence alerts are off for this child.',
            'enabled' => $enabled,
        ]);
    }

    private function settings(School $school): array
    {
        $today = now()->toDateString();

        return [
            'enabled' => (bool) $school->absence_alerts_enabled,
            'sms' => (bool) $school->absence_alerts_sms,
            // SMS can be allowed before an SMS account is set up; say so.
            'sms_configured' => $this->sms->isConfigured($school->id),
            'parents_opted_in' => (int) DB::table('student_guardian')
                ->join('students', 'students.id', '=', 'student_guardian.student_id')
                ->where('students.school_id', $school->id)
                ->where('student_guardian.absence_alerts', true)
                ->count(),
            'sent_today' => (int) DB::table('absence_alerts')
                ->where('school_id', $school->id)
                ->where('date', $today)
                ->where('outcome', 'sent')
                ->count(),
        ];
    }

    private function audit(Request $request, int $schoolId, string $action, string $type, int $id, ?array $old, array $new): void
    {
        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
