<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LiveClass;
use App\Models\LiveClassParticipant;
use App\Models\LmsSetting;
use App\Models\Student;
use App\Services\Lms\LmsService;
use App\Support\InAppNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Taking part in a virtual class, and the attendance that results (TASK 14
 * §3.3). The video call is the provider's; SchoolPilot hands out the link
 * only to the class it is for, and counts time from its own lesson page:
 * join, a heartbeat while the page is open, leave. Joining alone is not
 * attendance, and offline study never counts as a live lesson (§12).
 */
class VirtualClassroomController extends Controller
{
    public function __construct(private LmsService $lms)
    {
    }

    public function join(Request $request, int $id): JsonResponse
    {
        [$lc, $student] = $this->forStudent($request, $id);
        abort_unless($lc->is_joinable, 409, $lc->status === 'cancelled' ? 'This class was cancelled.' : 'This class is not open yet. It opens five minutes before the start.');

        $p = $this->lms->join($lc, $student);

        return response()->json([
            'meeting_url' => $this->joinUrl($lc, $student, $request->user()->name), 'meeting_id' => $lc->meeting_id, 'passcode' => $lc->passcode,
            'recording_notice' => $lc->recording_enabled ? LmsSetting::for($lc->school_id)->recording_notice : null,
            'joins' => $p->joins,
            'heartbeat_seconds' => 60,
        ]);
    }

    public function heartbeat(Request $request, int $id): JsonResponse
    {
        [$lc, $student] = $this->forStudent($request, $id);
        $p = $this->lms->heartbeat($lc, $student, $request->boolean('leaving'));
        abort_unless($p, 409, 'Join the class first.');

        return response()->json(['seconds_connected' => $p->seconds_connected, 'connected' => $p->connected]);
    }

    /** The class list with each student's time and status. */
    public function attendance(Request $request, int $id): JsonResponse
    {
        $lc = $this->forStaff($request, $id);
        $settings = LmsSetting::for($lc->school_id);
        $participants = LiveClassParticipant::where('live_class_id', $lc->id)->get()->keyBy('student_id');
        $students = $this->lms->studentsOf($lc->school_id, $lc->class_id, $lc->arm_id)->load('user:id,name');

        $rows = $students->map(function ($s) use ($participants, $lc, $settings) {
            $p = $participants->get($s->id);

            return [
                'student_id' => $s->id, 'name' => $s->user?->name, 'admission_number' => $s->admission_number,
                'first_joined_at' => $p?->first_joined_at?->toIso8601String(), 'last_left_at' => $p?->last_left_at?->toIso8601String(),
                'minutes_connected' => $p ? round($p->seconds_connected / 60, 1) : 0, 'joins' => $p->joins ?? 0,
                'minutes_in_call' => $lc->provider_sync_status === 'synced' ? round(($p?->provider_seconds ?? 0) / 60, 1) : null,
                'rejoined' => ($p->joins ?? 0) > 1,
                'status' => $this->lms->status($p, $lc, $settings),
                'corrected' => (bool) $p?->corrected_status, 'correction_reason' => $p?->correction_reason,
            ];
        })->sortBy('name')->values();

        return response()->json([
            'class' => ['id' => $lc->id, 'title' => $lc->title, 'scheduled_for' => $lc->scheduled_for?->toIso8601String(), 'duration_minutes' => $lc->duration_minutes, 'status' => $lc->status],
            'rules' => ['present_percent' => $settings->present_percent, 'late_grace_minutes' => $settings->late_grace_minutes],
            'counts' => $rows->countBy('status'),
            'students' => $rows,
            'source' => $lc->provider_sync_status === 'synced' ? 'provider' : 'page',
            'provider_sync' => ['status' => $lc->provider_sync_status, 'synced_at' => $lc->provider_synced_at?->toIso8601String(), 'error' => $lc->provider_sync_error,
                'can_sync' => in_array($lc->provider, \App\Services\Lms\ProviderAttendanceService::PROVIDERS, true)],
            'unmatched' => app(\App\Services\Lms\ProviderAttendanceService::class)->unmatched($lc),
        ]);
    }

    /** A teacher's correction, e.g. for a student whose connection dropped. Always with a reason. */
    public function correct(Request $request, int $id, int $studentId): JsonResponse
    {
        $lc = $this->forStaff($request, $id);
        $data = $request->validate(['status' => 'required|in:present,late,partial,absent', 'reason' => 'required|string|min:3|max:500']);
        abort_unless($this->lms->studentsOf($lc->school_id, $lc->class_id, $lc->arm_id)->contains('id', $studentId), 404);

        $p = LiveClassParticipant::firstOrNew(['live_class_id' => $lc->id, 'student_id' => $studentId], ['school_id' => $lc->school_id]);
        $before = $this->lms->status($p->exists ? $p : null, $lc, LmsSetting::for($lc->school_id));
        $p->fill(['corrected_status' => $data['status'], 'correction_reason' => $data['reason'], 'corrected_by' => $request->user()->id, 'corrected_at' => now()])->save();
        $this->lms->audit($request->user(), 'lms.virtual_attendance_corrected', LiveClassParticipant::class, $p->id, ['live_class_id' => $lc->id, 'student_id' => $studentId, 'from' => $before, 'to' => $data['status'], 'reason' => $data['reason']]);

        return response()->json(['message' => 'Attendance corrected.', 'status' => $data['status']]);
    }

    /** Cancel one session, or the rest of its weekly series, and tell the families. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $lc = $this->forStaff($request, $id);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500', 'whole_series' => 'boolean']);

        $sessions = ($data['whole_series'] ?? false) && $lc->series_id
            ? LiveClass::where('series_id', $lc->series_id)->where('scheduled_for', '>=', $lc->scheduled_for)->whereIn('status', ['scheduled', 'live'])->get()
            : collect([$lc]);
        foreach ($sessions as $s) {
            $s->update(['status' => 'cancelled', 'cancel_reason' => $data['reason'], 'ended_at' => $s->ended_at ?? now()]);
        }

        $students = $this->lms->studentsOf($lc->school_id, $lc->class_id, $lc->arm_id);
        $text = ($sessions->count() > 1 ? "{$lc->title}: the remaining {$sessions->count()} sessions are cancelled" : "{$lc->title} on ".$lc->scheduled_for->format('D j M, g:ia').' is cancelled').". {$data['reason']}";
        InAppNotifier::send($lc->school_id, $students->pluck('user_id')->map(fn ($v) => (int) $v)->all(), 'lms', 'Virtual class cancelled', $text);
        InAppNotifier::send($lc->school_id, $this->lms->guardianUserIds($students->pluck('id')->all()), 'lms', 'Virtual class cancelled', $text);

        return response()->json(['message' => "Cancelled {$sessions->count()} session(s). Students and parents were told.", 'cancelled' => $sessions->count()]);
    }

    /**
     * Zoom's join link takes the name to show in the call. "Ada Obi (HT/001)"
     * lets the participant report be matched to the right student.
     */
    private function joinUrl(LiveClass $lc, Student $student, string $name): ?string
    {
        if ($lc->provider !== 'zoom' || ! $lc->meeting_url || ! $student->admission_number) {
            return $lc->meeting_url;
        }

        return $lc->meeting_url.(str_contains($lc->meeting_url, '?') ? '&' : '?').'uname='.rawurlencode("{$name} ({$student->admission_number})");
    }

    /** @return array{0: LiveClass, 1: Student} */
    private function forStudent(Request $request, int $id): array
    {
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();
        $lc = LiveClass::where('school_id', $this->schoolId())->findOrFail($id);
        // Only the class it is for (§16): any other student gets nothing.
        abort_unless((int) $lc->class_id === (int) $student->class_id && (! $lc->arm_id || (int) $lc->arm_id === (int) $student->arm_id), 404);

        return [$lc, $student];
    }

    private function forStaff(Request $request, int $id): LiveClass
    {
        $lc = LiveClass::where('school_id', $this->schoolId())->findOrFail($id);
        $user = $request->user();
        abort_unless((int) $lc->teacher_id === $user->id || $this->lms->isAdmin($user)
            || ($lc->class_id && in_array($this->lms->sectionOfClass($lc->class_id), $this->lms->sectionsHeaded($user, $lc->school_id) ?? [], true)), 403, 'Only the class teacher, the section head or an admin manages this class.');

        return $lc;
    }
}
