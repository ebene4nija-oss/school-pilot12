<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRefusal;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelCharge;
use App\Models\HostelEnrollment;
use App\Models\HostelFeeRule;
use App\Models\HostelIncident;
use App\Models\HostelLeaveRequest;
use App\Models\HostelMaintenanceRequest;
use App\Models\HostelMovement;
use App\Models\HostelRoom;
use App\Models\HostelSetting;
use App\Models\HostelStaffAssignment;
use App\Models\HostelWelfareRecord;
use App\Models\Student;
use App\Services\Hostel\HostelService;
use App\Services\Results\AuthorisationFailure;
use App\Support\InAppNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Hostel management: structure, enrolment, allocation, arrivals and
 * departures, charges and reports (TASK 17). Daily operations and care are
 * in HostelOpsController; family and student views in HostelPortalController.
 */
class HostelController extends Controller
{
    public function __construct(private HostelService $hostels)
    {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $scope = $this->hostels->hostelsFor($request->user());
        $rooms = $this->hostels->rooms($schoolId, $scope);
        $residentIds = $this->residentIds($schoolId, $scope);

        $expected = HostelEnrollment::where('school_id', $schoolId)->whereIn('status', ['approved', 'active'])
            ->whereNotNull('expected_arrival')->where('expected_arrival', '<=', now()->addDays(7)->toDateString())
            ->get()->filter(fn ($e) => $this->hostels->presence($e->student_id) === 'not_arrived');

        $onLeave = HostelLeaveRequest::where('school_id', $schoolId)->where('status', 'departed')->whereIn('student_id', $residentIds ?: [0])->count();
        $overdue = $this->hostels->overdue($schoolId)->whereIn('student_id', $residentIds);

        $capacity = $rooms->sum('usable_beds');
        $occupied = $rooms->sum('occupied');

        $alerts = [];
        foreach ($overdue as $l) {
            $alerts[] = ['kind' => 'overdue_return', 'text' => ($l->student?->user?->name ?? 'A student').' was due back '.$l->return_at->diffForHumans().'.'];
        }
        foreach ($rooms->where('over_capacity', true) as $r) {
            $alerts[] = ['kind' => 'over_capacity', 'text' => "{$r['hostel']} {$r['name']} is above its approved capacity."];
        }
        foreach ($expected->filter(fn ($e) => $e->expected_arrival->isPast()) as $e) {
            $alerts[] = ['kind' => 'not_arrived', 'text' => (Student::with('user:id,name')->find($e->student_id)?->user?->name ?? 'A student').' was expected on '.$e->expected_arrival->format('j M').' and has not checked in.'];
        }

        return response()->json([
            'cards' => [
                'total_capacity' => $capacity,
                'physical_capacity' => $rooms->sum('physical_beds'),
                'occupied' => $occupied,
                'available' => max(0, $capacity - $occupied - $rooms->sum('reserved')),
                'occupancy_rate' => $capacity > 0 ? round($occupied / $capacity * 100, 1) : null,
                'expected_arrivals' => $expected->count(),
                'on_leave' => $onLeave,
                'overdue_returns' => $overdue->count(),
                'open_incidents' => $this->hostels->seesWelfare($request->user()) ? HostelIncident::where('school_id', $schoolId)->whereNotIn('status', ['resolved', 'closed'])->count() : null,
                'open_maintenance' => HostelMaintenanceRequest::where('school_id', $schoolId)->whereNotIn('status', ['completed', 'verified', 'cancelled'])->when($scope !== null, fn ($q) => $q->whereIn('hostel_id', $scope ?: [0]))->count(),
            ],
            'alerts' => $alerts,
            'out_of_service_beds' => $rooms->sum(fn ($r) => $r['physical_beds'] - $r['usable_beds']),
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(HostelSetting::for($this->schoolId()));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parents_see_bed' => 'sometimes|boolean',
            'parents_request_leave' => 'sometimes|boolean',
            'students_request_leave' => 'sometimes|boolean',
            'students_report_maintenance' => 'sometimes|boolean',
            'consent_leave_types' => 'sometimes|array',
            'consent_leave_types.*' => 'in:weekend,medical,outing,family,other',
            'return_grace_minutes' => 'sometimes|integer|min:0|max:720',
        ]);

        $s = HostelSetting::withoutGlobalScopes()->firstOrNew(['school_id' => $this->schoolId()]);
        $s->fill($data)->save();
        $this->hostels->audit($request->user(), 'hostel.settings_updated', HostelSetting::class, $s->id, $data);

        return response()->json($s);
    }

    // ==================================================================
    // Structure (§5)
    // ==================================================================

    public function hostelsList(Request $request): JsonResponse
    {
        $scope = $this->hostels->hostelsFor($request->user());

        return response()->json(['data' => Hostel::where('school_id', $this->schoolId())->when($scope !== null, fn ($q) => $q->whereIn('id', $scope ?: [0]))->orderBy('name')->get()]);
    }

    public function storeHostel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('hostels', 'name')->where('school_id', $this->schoolId())],
            'code' => 'nullable|string|max:30',
            'campus' => 'nullable|string|max:120',
            'placement_policy' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        return response()->json(['hostel' => Hostel::create($data + ['school_id' => $this->schoolId(), 'status' => 'active'])], 201);
    }

    public function updateHostel(Request $request, int $id): JsonResponse
    {
        $hostel = Hostel::where('school_id', $this->schoolId())->findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:120', 'code' => 'sometimes|nullable|string|max:30', 'campus' => 'sometimes|nullable|string|max:120',
            'placement_policy' => 'sometimes|nullable|string|max:255', 'status' => 'sometimes|in:active,inactive,closed', 'notes' => 'sometimes|nullable|string|max:2000',
        ]);

        $hostel->update($data);

        return response()->json(['hostel' => $hostel->fresh()]);
    }

    public function structure(Request $request): JsonResponse
    {
        $scope = $this->hostels->hostelsFor($request->user());
        $hostelId = $request->integer('hostel_id') ?: null;

        if ($hostelId) {
            $scope = $scope === null ? [$hostelId] : array_values(array_intersect($scope, [$hostelId]));
        }

        return response()->json(['rooms' => $this->hostels->rooms($this->schoolId(), $scope)]);
    }

    public function storeRoom(Request $request, int $hostelId): JsonResponse
    {
        $hostel = Hostel::where('school_id', $this->schoolId())->findOrFail($hostelId);
        $this->attempt(fn () => $this->hostels->assertHostel($request->user(), $hostel->id, ['manager']));

        $data = $request->validate([
            'name' => 'required|string|max:60', 'block' => 'nullable|string|max:60', 'floor' => 'nullable|string|max:30',
            'room_type' => 'nullable|string|max:30', 'capacity' => 'required|integer|min:1|max:64',
            'facilities' => 'nullable|array', 'restrictions' => 'nullable|string|max:255',
        ]);

        if (HostelRoom::where('hostel_id', $hostel->id)->where('name', $data['name'])->where('block', $data['block'] ?? null)->exists()) {
            return response()->json(['message' => 'That room already exists in this hostel.'], 422);
        }

        return response()->json(['room' => $this->hostels->createRoom($hostel, $data)], 201);
    }

    /** Capacity changes are permission-controlled and audited (§5). */
    public function updateRoom(Request $request, int $id): JsonResponse
    {
        $room = HostelRoom::where('school_id', $this->schoolId())->findOrFail($id);
        $this->attempt(fn () => $this->hostels->assertHostel($request->user(), $room->hostel_id, ['manager']));

        $data = $request->validate([
            'capacity' => 'sometimes|integer|min:1|max:64', 'status' => 'sometimes|in:available,out_of_service',
            'room_type' => 'sometimes|nullable|string|max:30', 'facilities' => 'sometimes|nullable|array',
            'restrictions' => 'sometimes|nullable|string|max:255', 'reason' => 'required_with:capacity,status|nullable|string|max:255',
        ]);

        $old = $room->only(['capacity', 'status']);
        $room->update(collect($data)->except('reason')->all());

        // Adding capacity adds beds; reducing it never removes a bed in use.
        $beds = HostelBed::where('room_id', $room->id)->count();
        for ($i = $beds + 1; $i <= (int) $room->capacity; $i++) {
            HostelBed::create(['school_id' => $room->school_id, 'room_id' => $room->id, 'label' => "B{$i}", 'status' => 'available']);
        }

        $this->hostels->audit($request->user(), 'hostel.room_updated', HostelRoom::class, $room->id, ['old' => $old, 'new' => $room->only(['capacity', 'status']), 'reason' => $data['reason'] ?? null]);

        return response()->json(['room' => $room->fresh()]);
    }

    public function bedStatus(Request $request, int $id): JsonResponse
    {
        $bed = HostelBed::where('school_id', $this->schoolId())->findOrFail($id);
        $room = HostelRoom::findOrFail($bed->room_id);
        $this->attempt(fn () => $this->hostels->assertHostel($request->user(), $room->hostel_id, ['manager']));
        $data = $request->validate(['status' => 'required|in:available,out_of_service', 'note' => 'required|string|max:255']);

        if ($data['status'] === 'out_of_service' && HostelAllocation::where('bed_id', $bed->id)->whereIn('status', ['active', 'reserved'])->exists()) {
            return response()->json(['message' => 'Move the student out of this bed first.'], 409);
        }

        $bed->update(['status' => $data['status'], 'status_note' => $data['note'], 'status_changed_at' => now()]);
        $this->hostels->audit($request->user(), 'hostel.bed_status', HostelBed::class, $bed->id, $data);

        return response()->json(['bed' => $bed->fresh()]);
    }

    // ==================================================================
    // Staff (§16)
    // ==================================================================

    public function staff(): JsonResponse
    {
        return response()->json(['data' => HostelStaffAssignment::where('school_id', $this->schoolId())->orderByRaw('ends_on IS NOT NULL')->orderBy('hostel_id')->get()
            ->map(fn ($s) => $s->toArray() + ['name' => DB::table('users')->where('id', $s->user_id)->value('name'), 'hostel' => DB::table('hostels')->where('id', $s->hostel_id)->value('name')])]);
    }

    public function assignStaff(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'hostel_id' => ['required', Rule::exists('hostels', 'id')->where('school_id', $schoolId)],
            'user_id' => ['required', Rule::exists('user_profiles', 'user_id')->where('school_id', $schoolId)->whereIn('role', ['teacher', 'nurse', 'school_admin'])],
            'role' => 'required|in:manager,warden,house_parent,matron',
            'starts_on' => 'nullable|date',
        ]);

        $row = HostelStaffAssignment::create($data + ['school_id' => $schoolId, 'starts_on' => $data['starts_on'] ?? now()->toDateString()]);
        $this->hostels->audit($request->user(), 'hostel.staff_assigned', HostelStaffAssignment::class, $row->id, $data);

        return response()->json(['assignment' => $row], 201);
    }

    public function endStaff(Request $request, int $id): JsonResponse
    {
        $row = HostelStaffAssignment::where('school_id', $this->schoolId())->whereNull('ends_on')->findOrFail($id);
        $row->update(['ends_on' => now()->toDateString()]);
        $this->hostels->audit($request->user(), 'hostel.staff_ended', HostelStaffAssignment::class, $row->id, []);

        return response()->json(['message' => 'Assignment ended. It stays in the record.']);
    }

    // ==================================================================
    // Enrolment and allocation (§7, §8)
    // ==================================================================

    public function enrollments(Request $request): JsonResponse
    {
        $rows = HostelEnrollment::where('school_id', $this->schoolId())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with('student.user:id,name', 'student.currentClass:id,name')->orderByDesc('id')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn ($e) => $e->only(['id', 'student_id', 'boarding_category', 'status', 'starts_on', 'ends_on', 'expected_arrival', 'decision_note']) + [
            'student_name' => $e->student?->user?->name, 'class' => $e->student?->currentClass?->name,
            'has_bed' => HostelAllocation::where('student_id', $e->student_id)->whereIn('status', ['active', 'reserved'])->exists(),
        ])]);
    }

    public function storeEnrollment(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'boarding_category' => 'required|in:full,weekly,flexible,day_services',
            'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on', 'expected_arrival' => 'nullable|date',
        ]);

        if (HostelEnrollment::where('student_id', $data['student_id'])->whereIn('status', ['submitted', 'under_review', 'approved', 'active', 'waitlisted'])->exists()) {
            return response()->json(['message' => 'This student already has an open hostel enrolment.'], 409);
        }

        $row = HostelEnrollment::create($data + ['school_id' => $schoolId, 'status' => 'submitted', 'requested_by' => $request->user()->id, 'session_id' => \App\Support\TeacherScope::currentSessionId($schoolId)]);

        return response()->json(['enrollment' => $row], 201);
    }

    public function decideEnrollment(Request $request, int $id): JsonResponse
    {
        $e = HostelEnrollment::where('school_id', $this->schoolId())->findOrFail($id);
        $data = $request->validate(['decision' => 'required|in:approve,reject,waitlist', 'note' => 'nullable|string|max:500']);

        return $this->attempt(function () use ($e, $request, $data) {
            $e = $this->hostels->decideEnrollment($e, $request->user(), $data['decision'], $data['note'] ?? null);
            $this->notifyGuardians($e->student_id, 'Hostel application '.$e->status, 'The school has '.$e->status.' the hostel place. Open the hostel page for details.');

            return ['enrollment' => $e];
        });
    }

    public function residents(Request $request): JsonResponse
    {
        $scope = $this->hostels->hostelsFor($request->user());

        $rows = DB::table('hostel_allocations as a')
            ->join('hostel_beds as b', 'b.id', '=', 'a.bed_id')
            ->join('hostel_rooms as r', 'r.id', '=', 'b.room_id')
            ->join('hostels as h', 'h.id', '=', 'r.hostel_id')
            ->join('students as s', 's.id', '=', 'a.student_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('classes as c', 'c.id', '=', 's.class_id')
            ->where('a.school_id', $this->schoolId())->whereIn('a.status', ['active', 'reserved'])
            ->when($scope !== null, fn ($q) => $q->whereIn('h.id', $scope ?: [0]))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('u.name', 'like', '%'.$request->input('search').'%')->orWhere('s.admission_number', 'like', '%'.$request->input('search').'%')->orWhere('r.name', 'like', '%'.$request->input('search').'%')))
            ->orderBy('h.name')->orderBy('r.name')->orderBy('b.label')
            ->get(['a.id as allocation_id', 'a.status as allocation_status', 'a.starts_on', 's.id as student_id', 'u.name', 's.admission_number', 'c.name as class', 'h.id as hostel_id', 'h.name as hostel', 'r.name as room', 'r.block', 'b.id as bed_id', 'b.label as bed']);

        return response()->json(['data' => $rows->map(fn ($r) => (array) $r + ['presence' => $this->hostels->presence((int) $r->student_id)])]);
    }

    public function allocate(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'bed_id' => ['required', Rule::exists('hostel_beds', 'id')->where('school_id', $schoolId)],
            'starts_on' => 'nullable|date', 'reserve' => 'boolean', 'reason' => 'nullable|string|max:255', 'override_reason' => 'nullable|string|max:500',
        ]);

        $bed = HostelBed::findOrFail($data['bed_id']);

        return $this->attempt(function () use ($request, $data, $bed, $schoolId) {
            $this->hostels->assertHostel($request->user(), (int) HostelRoom::find($bed->room_id)->hostel_id, ['manager']);
            $allocation = $this->hostels->allocate($schoolId, (int) $data['student_id'], $bed, $request->user(), $data);
            $this->notifyGuardians((int) $data['student_id'], 'Hostel bed allocated', 'Your child has been given a hostel bed. Open the hostel page for details.');

            return ['allocation' => $allocation];
        }, 201);
    }

    public function move(Request $request, int $id): JsonResponse
    {
        $current = HostelAllocation::where('school_id', $this->schoolId())->findOrFail($id);
        $data = $request->validate(['bed_id' => ['required', Rule::exists('hostel_beds', 'id')->where('school_id', $this->schoolId())], 'reason' => 'required|string|min:3|max:255']);

        return $this->attempt(fn () => ['allocation' => $this->hostels->move($current, HostelBed::findOrFail($data['bed_id']), $request->user(), $data['reason'])]);
    }

    public function endAllocation(Request $request, int $id): JsonResponse
    {
        $a = HostelAllocation::where('school_id', $this->schoolId())->whereIn('status', ['active', 'reserved'])->findOrFail($id);
        $data = $request->validate(['reason' => 'required|string|min:3|max:255']);
        $this->hostels->endAllocation($a, $request->user(), $data['reason']);

        return response()->json(['message' => 'Allocation ended. The bed is free; the history is kept.']);
    }

    /** One boarder's hostel profile (§6): enrolment, beds over time, movements, leave. */
    public function studentProfile(Request $request, int $id): JsonResponse
    {
        $student = Student::where('school_id', $this->schoolId())->with('user:id,name', 'currentClass:id,name')->findOrFail($id);

        return response()->json([
            'student' => ['id' => $student->id, 'name' => $student->user?->name, 'admission_number' => $student->admission_number, 'class' => $student->currentClass?->name],
            'presence' => $this->hostels->presence($student->id),
            'enrollments' => HostelEnrollment::where('student_id', $student->id)->orderByDesc('id')->get(),
            'allocations' => HostelAllocation::where('student_id', $student->id)->with('bed.room.hostel:id,name')->orderByDesc('id')->get()
                ->map(fn ($a) => $a->only(['id', 'status', 'starts_on', 'ends_on', 'reason', 'end_reason']) + ['bed' => $a->bed?->label, 'room' => $a->bed?->room?->name, 'hostel' => $a->bed?->room?->hostel?->name]),
            'movements' => HostelMovement::where('student_id', $student->id)->orderByDesc('occurred_at')->limit(100)->get(),
            'leave' => HostelLeaveRequest::where('student_id', $student->id)->orderByDesc('id')->limit(50)->get(),
            'welfare_open' => $this->hostels->seesWelfare($request->user()) ? HostelWelfareRecord::where('student_id', $student->id)->where('status', 'open')->count() : null,
        ]);
    }

    // ==================================================================
    // Arrivals and departures (§9)
    // ==================================================================

    public function arrivals(): JsonResponse
    {
        $rows = HostelEnrollment::where('school_id', $this->schoolId())->whereIn('status', ['approved', 'active'])->with('student.user:id,name')->get()
            ->map(fn ($e) => ['student_id' => $e->student_id, 'name' => $e->student?->user?->name, 'expected_arrival' => $e->expected_arrival?->toDateString(), 'presence' => $this->hostels->presence($e->student_id)])
            ->filter(fn ($r) => in_array($r['presence'], ['not_arrived', 'checked_out'], true))->values();

        return response()->json(['data' => $rows]);
    }

    /** One student or a group; an event is recorded for each (§9). */
    public function checkIn(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'student_ids' => 'required|array|min:1|max:200',
            'student_ids.*' => [Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'checklist' => 'nullable|array', 'notes' => 'nullable|string|max:500',
        ]);

        $results = [];
        foreach ($data['student_ids'] as $id) {
            try {
                $this->hostels->checkIn($schoolId, (int) $id, $request->user(), $data['checklist'] ?? [], $data['notes'] ?? null);
                $results[] = ['student_id' => (int) $id, 'ok' => true];
                $this->notifyGuardians((int) $id, 'Arrived at the hostel', 'Your child has checked in at the hostel.');
            } catch (DomainRefusal $e) {
                $results[] = ['student_id' => (int) $id, 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        return response()->json(['results' => $results, 'checked_in' => count(array_filter($results, fn ($r) => $r['ok']))]);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'ends_stay' => 'required|boolean', 'collected_by' => 'nullable|string|max:120', 'checklist' => 'nullable|array', 'notes' => 'nullable|string|max:500',
        ]);

        $m = $this->hostels->checkOut($schoolId, (int) $data['student_id'], $request->user(), $data['ends_stay'], $data['collected_by'] ?? null, $data['checklist'] ?? [], $data['notes'] ?? null);
        $this->notifyGuardians((int) $data['student_id'], 'Checked out of the hostel', 'Your child has been checked out of the hostel.');

        return response()->json(['movement' => $m, 'message' => $data['ends_stay'] ? 'Checked out; the stay has ended and the bed is free.' : 'Checked out for now; the bed stays theirs.']);
    }

    // ==================================================================
    // Charges through Finance (§19)
    // ==================================================================

    public function feeRules(): JsonResponse
    {
        return response()->json(['data' => HostelFeeRule::where('school_id', $this->schoolId())->orderBy('boarding_category')->get()]);
    }

    public function storeFeeRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'boarding_category' => 'required|in:full,weekly,flexible,day_services',
            'charge_type' => 'required|in:boarding,meals,deposit,laundry,other',
            'title' => 'required|string|max:120',
            'amount' => 'required|numeric|min:0|max:100000000',
        ]);

        return response()->json(['rule' => HostelFeeRule::create($data + ['school_id' => $this->schoolId(), 'is_active' => true])], 201);
    }

    public function bill(Request $request): JsonResponse
    {
        $data = $request->validate(['term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $this->schoolId())]]);
        $result = $this->hostels->bill($this->schoolId(), (int) $data['term_id'], $request->user());

        return response()->json($result + ['message' => "{$result['posted']} charge(s) added to term invoices.".($result['awaiting_invoice'] ? " {$result['awaiting_invoice']} wait for the student's term invoice to be generated in Finance." : '')]);
    }

    public function charges(Request $request): JsonResponse
    {
        $charges = HostelCharge::where('school_id', $this->schoolId())->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->integer('term_id')))
            ->orderByDesc('id')->limit(500)->get();
        // Who each charge is for, so Finance can read the list without a lookup per row.
        $names = \App\Models\Student::whereIn('students.id', $charges->pluck('student_id')->unique())
            ->join('users', 'users.id', '=', 'students.user_id')->pluck('users.name', 'students.id');

        return response()->json(['data' => $charges->map(fn ($c) => $c->toArray() + ['student_name' => $names[$c->student_id] ?? null])]);
    }

    // ==================================================================
    // Announcements and reports (§20, §21)
    // ==================================================================

    /** Notices carry no sensitive detail: they go to lock screens. */
    public function announce(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'audience' => 'required|in:residents,parents,staff,both',
            'hostel_id' => ['nullable', Rule::exists('hostels', 'id')->where('school_id', $schoolId)],
            'title' => 'required|string|max:120',
            'body' => 'required|string|max:1000',
        ]);

        if ($data['hostel_id'] ?? null) {
            $this->attempt(fn () => $this->hostels->assertHostel($request->user(), (int) $data['hostel_id'], ['manager', 'warden', 'house_parent']));
        }

        $studentIds = $this->residentIds($schoolId, ($data['hostel_id'] ?? null) ? [(int) $data['hostel_id']] : null);
        $users = [];

        if (in_array($data['audience'], ['residents', 'both'], true)) {
            $users = array_merge($users, Student::whereIn('id', $studentIds)->pluck('user_id')->all());
        }
        if (in_array($data['audience'], ['parents', 'both'], true)) {
            $users = array_merge($users, DB::table('student_guardian')->join('guardians', 'guardians.id', '=', 'student_guardian.guardian_id')->whereIn('student_guardian.student_id', $studentIds)->pluck('guardians.user_id')->all());
        }
        if ($data['audience'] === 'staff') {
            $users = HostelStaffAssignment::where('school_id', $schoolId)->whereNull('ends_on')->when($data['hostel_id'] ?? null, fn ($q) => $q->where('hostel_id', $data['hostel_id']))->pluck('user_id')->all();
        }

        InAppNotifier::send($schoolId, array_map('intval', $users), 'hostel_notice', $data['title'], $data['body']);
        $this->hostels->audit($request->user(), 'hostel.announcement', Hostel::class, (int) ($data['hostel_id'] ?? 0), ['audience' => $data['audience'], 'recipients' => count(array_unique($users))]);

        return response()->json(['message' => 'Sent to '.count(array_unique($users)).' people.']);
    }

    /** Operational reports as CSV; welfare and incidents are not offered here (§21). */
    public function report(Request $request, string $kind)
    {
        $schoolId = $this->schoolId();
        $scope = $this->hostels->hostelsFor($request->user());
        abort_unless(in_array($kind, ['occupancy', 'residents', 'on_leave', 'overdue', 'maintenance'], true), 404);

        $rows = match ($kind) {
            'occupancy' => $this->hostels->rooms($schoolId, $scope)->map(fn ($r) => ['Hostel' => $r['hostel'], 'Block' => $r['block'], 'Room' => $r['name'], 'Capacity' => $r['capacity'], 'Usable beds' => $r['usable_beds'], 'Occupied' => $r['occupied'], 'Reserved' => $r['reserved'], 'Available' => $r['available']]),
            'residents' => collect($this->residents($request)->getData(true)['data'])->map(fn ($r) => ['Hostel' => $r['hostel'], 'Room' => $r['room'], 'Bed' => $r['bed'], 'Student' => $r['name'], 'Admission No.' => $r['admission_number'], 'Class' => $r['class'], 'Since' => $r['starts_on']]),
            'on_leave' => HostelLeaveRequest::where('school_id', $schoolId)->where('status', 'departed')->with('student.user:id,name')->get()->map(fn ($l) => ['Student' => $l->student?->user?->name, 'Type' => $l->leave_type, 'Left' => $l->depart_at?->format('Y-m-d H:i'), 'Due back' => $l->return_at?->format('Y-m-d H:i')]),
            'overdue' => $this->hostels->overdue($schoolId)->map(fn ($l) => ['Student' => $l->student?->user?->name, 'Due back' => $l->return_at?->format('Y-m-d H:i')]),
            'maintenance' => HostelMaintenanceRequest::where('school_id', $schoolId)->whereNotIn('status', ['verified', 'cancelled'])->get()->map(fn ($m) => ['Reference' => $m->reference, 'Category' => $m->category, 'Priority' => $m->priority, 'Status' => $m->status, 'Reported' => $m->created_at?->format('Y-m-d')]),
        };

        AuditLog::create(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'action' => 'hostel.report_exported', 'auditable_type' => Hostel::class, 'auditable_id' => 0, 'new_values' => ['kind' => $kind, 'rows' => count($rows)]]);

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        if ($rows->isNotEmpty()) {
            fputcsv($out, array_keys($rows->first()));
        }
        foreach ($rows as $r) {
            fputcsv($out, array_values($r));
        }
        rewind($out);

        return response(stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"hostel-{$kind}-".now()->format('Ymd').'.csv"']);
    }

    // ==================================================================

    /** @return array<int, int> */
    public function residentIds(int $schoolId, ?array $hostelIds): array
    {
        return DB::table('hostel_allocations as a')->join('hostel_beds as b', 'b.id', '=', 'a.bed_id')->join('hostel_rooms as r', 'r.id', '=', 'b.room_id')
            ->where('a.school_id', $schoolId)->where('a.status', 'active')
            ->when($hostelIds !== null, fn ($q) => $q->whereIn('r.hostel_id', $hostelIds ?: [0]))
            ->pluck('a.student_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function notifyGuardians(int $studentId, string $title, string $body): void
    {
        $users = DB::table('student_guardian')->join('guardians', 'guardians.id', '=', 'student_guardian.guardian_id')->where('student_guardian.student_id', $studentId)->pluck('guardians.user_id')->all();
        InAppNotifier::send($this->schoolId(), array_map('intval', $users), 'hostel', $title, $body);
    }

    private function attempt(callable $fn, int $status = 200): JsonResponse
    {
        try {
            $result = $fn();

            return response()->json($result ?? ['ok' => true], $status);
        } catch (\App\Services\Hostel\AllocationRefused $e) {
            abort(response()->json(['message' => $e->getMessage(), 'errors' => [$e->field => [$e->getMessage()]]], 422));
        } catch (AuthorisationFailure $e) {
            abort(response()->json(['message' => $e->getMessage()], 403));
        } catch (DomainRefusal $e) {
            abort(response()->json(['message' => $e->getMessage()], 409));
        }
    }
}
