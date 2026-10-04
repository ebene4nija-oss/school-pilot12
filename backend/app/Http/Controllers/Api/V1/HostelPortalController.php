<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HostelAllocation;
use App\Models\HostelCharge;
use App\Models\HostelEnrollment;
use App\Models\HostelLeaveRequest;
use App\Models\HostelSetting;
use App\Models\Student;
use App\Services\Hostel\HostelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a family and a boarder see (TASK 17 §12, §13): their own child's or
 * their own boarding only. Never a room list, another child's attendance,
 * incident details or staff notes.
 */
class HostelPortalController extends Controller
{
    public function __construct(private HostelService $hostels)
    {
    }

    public function parent(Request $request): JsonResponse
    {
        $settings = HostelSetting::for($this->schoolId());
        $children = Student::whereHas('guardians', fn ($q) => $q->where('guardians.user_id', $request->user()->id))->with('user:id,name')->get();

        return response()->json([
            'can_request_leave' => $settings->parents_request_leave,
            'children' => $children->map(fn ($s) => $this->summary($s, $settings->parents_see_bed)),
        ]);
    }

    public function parentLeave(Request $request): JsonResponse
    {
        abort_unless(HostelSetting::for($this->schoolId())->parents_request_leave, 403, 'The school takes leave requests at the hostel desk.');
        $ops = app(HostelOpsController::class);
        $data = $ops->validateLeave($request);

        abort_unless(Student::findOrFail($data['student_id'])->isGuardedBy($request->user()), 404);

        // A parent's own request carries their consent.
        return response()->json(['leave' => $ops->createLeave($data, 'parent', $request->user()->id)], 201);
    }

    /** A guardian answering a consent request raised by the hostel. */
    public function parentConsent(Request $request, int $id): JsonResponse
    {
        $leave = HostelLeaveRequest::where('status', 'awaiting_consent')->findOrFail($id);
        abort_unless(Student::findOrFail($leave->student_id)->isGuardedBy($request->user()), 404);
        $data = $request->validate(['given' => 'required|boolean']);

        $leave->update(['guardian_consent' => $data['given'] ? 'given' : 'refused', 'status' => $data['given'] ? 'submitted' : 'rejected']);

        return response()->json(['leave' => $leave->fresh()->only(['id', 'status', 'guardian_consent'])]);
    }

    public function student(Request $request): JsonResponse
    {
        $student = Student::where('user_id', $request->user()->id)->with('user:id,name')->firstOrFail();
        $settings = HostelSetting::for($this->schoolId());

        return response()->json($this->summary($student, true) + [
            'can_request_leave' => $settings->students_request_leave,
            'can_report_maintenance' => $settings->students_report_maintenance,
        ]);
    }

    public function studentLeave(Request $request): JsonResponse
    {
        abort_unless(HostelSetting::for($this->schoolId())->students_request_leave, 403, 'Ask your house parent for leave.');
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();
        $request->merge(['student_id' => $student->id]);
        $ops = app(HostelOpsController::class);

        return response()->json(['leave' => $ops->createLeave($ops->validateLeave($request), 'student', $request->user()->id)], 201);
    }

    public function studentMaintenance(Request $request): JsonResponse
    {
        abort_unless(HostelSetting::for($this->schoolId())->students_report_maintenance, 403);
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();
        $hostel = $this->hostels->hostelOf($student->id);
        abort_unless($hostel, 409, 'Only a boarder can report a hostel repair.');

        $request->merge(['hostel_id' => $hostel]);
        $ops = app(HostelOpsController::class);

        return response()->json(['request' => $ops->createMaintenance(collect($ops->validateMaintenance($request))->only(['hostel_id', 'category', 'description'])->all(), $request->user()->id)->only(['reference', 'status'])], 201);
    }

    private function summary(Student $s, bool $showBed): array
    {
        $allocation = HostelAllocation::where('student_id', $s->id)->whereIn('status', ['active', 'reserved'])->with('bed.room.hostel:id,name')->first();
        $enrollment = HostelEnrollment::where('student_id', $s->id)->orderByDesc('id')->first();

        return [
            'student_id' => $s->id,
            'name' => $s->user?->name,
            'boarding' => $enrollment ? ['status' => $enrollment->status, 'category' => $enrollment->boarding_category, 'starts_on' => $enrollment->starts_on?->toDateString(), 'expected_arrival' => $enrollment->expected_arrival?->toDateString()] : null,
            'hostel' => $allocation?->bed?->room?->hostel?->name,
            'room' => $showBed ? $allocation?->bed?->room?->name : null,
            'bed' => $showBed ? $allocation?->bed?->label : null,
            'presence' => $allocation ? $this->hostels->presence($s->id) : null,
            'leave' => HostelLeaveRequest::where('student_id', $s->id)->orderByDesc('id')->limit(10)->get(['id', 'leave_type', 'depart_at', 'return_at', 'status', 'guardian_consent']),
            'charges' => HostelCharge::where('student_id', $s->id)->where('status', '!=', 'void')->orderByDesc('id')->limit(10)->get(['title', 'amount', 'status', 'term_id']),
        ];
    }
}
