<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The people half of §7.3.
 *
 * Payroll was implemented; leave allocation and employment history were JSON
 * columns nothing read, and there was no way to request or approve time off.
 */
class StaffHrController extends Controller
{
    private function schoolId(Request $request): ?int
    {
        return $request->user()?->userProfile?->school_id;
    }

    private function isAdmin(Request $request): bool
    {
        return in_array($request->user()?->userProfile?->role, ['super_admin', 'school_admin'], true);
    }

    private function currentStaff(Request $request): ?Staff
    {
        return Staff::where('user_id', $request->user()->id)->first();
    }

    // ------------------------------------------------------------------
    // Leave
    // ------------------------------------------------------------------

    /** Apply for leave. Staff apply for themselves; admins may file on behalf. */
    public function requestLeave(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $validated = $request->validate([
            'leave_type' => ['required', Rule::in(LeaveRequest::TYPES)],
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:1000',
            'staff_id' => 'nullable|integer',
        ]);

        $staff = $this->isAdmin($request) && ! empty($validated['staff_id'])
            ? Staff::where('school_id', $schoolId)->find($validated['staff_id'])
            : $this->currentStaff($request);

        if (! $staff) {
            return response()->json(['error' => 'No staff record found for this user.'], 404);
        }

        $start = Carbon::parse($validated['start_date']);
        $end = Carbon::parse($validated['end_date']);
        $days = $start->diffInDays($end) + 1;

        // Overlapping requests are almost always a double submission, and an
        // approver seeing the same week twice cannot tell which is current.
        $overlap = LeaveRequest::where('staff_id', $staff->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->exists();

        if ($overlap) {
            return response()->json([
                'error' => 'You already have leave requested or approved that overlaps these dates.',
            ], 409);
        }

        $leave = LeaveRequest::create([
            'school_id' => $staff->school_id,
            'staff_id' => $staff->id,
            'leave_type' => $validated['leave_type'],
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days_requested' => $days,
            'reason' => $validated['reason'] ?? null,
            'status' => 'pending',
        ]);

        $allowance = $staff->allowanceFor($validated['leave_type']);

        return response()->json([
            'message' => 'Leave requested.',
            'leave_request' => $leave,
            'entitlement' => [
                'days_allowed' => $allowance,
                'days_taken_this_year' => $staff->daysTakenThisYear($validated['leave_type']),
                'days_requested' => $days,
            ],
        ], 201);
    }

    /** My own requests, or the whole school's for an admin. */
    public function listLeave(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $query = LeaveRequest::where('school_id', $schoolId)
            ->with(['staff.user:id,name', 'decider:id,name']);

        if (! $this->isAdmin($request)) {
            $staff = $this->currentStaff($request);

            if (! $staff) {
                return response()->json(['error' => 'No staff record found for this user.'], 404);
            }

            $query->where('staff_id', $staff->id);
        }

        foreach (['status', 'leave_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        return response()->json($query->orderByDesc('id')->paginate(min((int) $request->input('per_page', 25), 100)));
    }

    /**
     * Approve or reject.
     *
     * Only pending requests can be decided — re-deciding a settled request
     * would silently rewrite a record someone has already planned around.
     */
    public function decideLeave(Request $request, $id)
    {
        $schoolId = $this->schoolId($request);

        $validated = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'decision_note' => 'nullable|string|max:1000',
        ]);

        $leave = LeaveRequest::where('school_id', $schoolId)->findOrFail($id);

        if (! $leave->isPending()) {
            return response()->json([
                'error' => "This request has already been {$leave->status}.",
            ], 409);
        }

        $leave->update([
            'status' => $validated['decision'],
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'decision_note' => $validated['decision_note'] ?? null,
        ]);

        return response()->json(['message' => "Leave {$validated['decision']}.", 'leave_request' => $leave->fresh()]);
    }

    /** Withdraw a request that has not been decided yet. */
    public function cancelLeave(Request $request, $id)
    {
        $staff = $this->currentStaff($request);

        if (! $staff) {
            return response()->json(['error' => 'No staff record found for this user.'], 404);
        }

        $leave = LeaveRequest::where('staff_id', $staff->id)->findOrFail($id);

        if (! $leave->isPending()) {
            return response()->json(['error' => 'Only a pending request can be withdrawn.'], 409);
        }

        $leave->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Request withdrawn.']);
    }

    /** Who is off on a given day — what a head teacher checks when covering classes. */
    public function leaveCalendar(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $date = $request->filled('date')
            ? Carbon::parse($request->input('date'))->toDateString()
            : now()->toDateString();

        $onLeave = LeaveRequest::where('school_id', $schoolId)
            ->where('status', 'approved')
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->with('staff.user:id,name')
            ->get()
            ->map(fn (LeaveRequest $l) => [
                'staff_id' => $l->staff_id,
                'name' => $l->staff?->user?->name,
                'leave_type' => $l->leave_type,
                'until' => $l->end_date?->toDateString(),
            ]);

        return response()->json(['date' => $date, 'on_leave' => $onLeave]);
    }

    // ------------------------------------------------------------------
    // Entitlements and employment history
    // ------------------------------------------------------------------

    /** Set a school's leave allowances for one member of staff. */
    public function setLeaveAllocation(Request $request, $staffId)
    {
        $validated = $request->validate([
            'allocations' => 'required|array|min:1',
            'allocations.*' => 'integer|min:0|max:365',
        ]);

        foreach (array_keys($validated['allocations']) as $type) {
            if (! in_array($type, LeaveRequest::TYPES, true)) {
                return response()->json([
                    'errors' => ['allocations' => ["'{$type}' is not a recognised leave type."]],
                ], 422);
            }
        }

        $staff = Staff::where('school_id', $this->schoolId($request))->findOrFail($staffId);
        $staff->update(['leave_allocations' => $validated['allocations']]);

        return response()->json([
            'message' => 'Leave allocation updated.',
            'leave_allocations' => $staff->fresh()->leave_allocations,
        ]);
    }

    /**
     * Employment record: allocations, balances, and prior posts.
     *
     * Staff read their own; admins read anyone's in their school.
     */
    public function employmentRecord(Request $request, $staffId)
    {
        $schoolId = $this->schoolId($request);
        $staff = Staff::where('school_id', $schoolId)->with('user:id,name')->findOrFail($staffId);

        if (! $this->isAdmin($request) && (int) $staff->user_id !== (int) $request->user()->id) {
            return response()->json(['error' => 'You can only view your own employment record.'], 403);
        }

        $balances = [];
        foreach (LeaveRequest::TYPES as $type) {
            $allowed = $staff->allowanceFor($type);
            $taken = $staff->daysTakenThisYear($type);

            $balances[$type] = [
                'days_allowed' => $allowed,
                'days_taken' => $taken,
                'days_remaining' => $allowed !== null ? max(0, $allowed - $taken) : null,
            ];
        }

        return response()->json([
            'staff_id' => $staff->id,
            'name' => $staff->user?->name,
            'designation' => $staff->designation,
            'employment_date' => $staff->employment_date?->toDateString(),
            'qualifications' => $staff->qualifications ?? [],
            'employment_history' => $staff->employment_history ?? [],
            'leave_balances' => $balances,
        ]);
    }

    /** Append a prior post to the employment history. Admin only. */
    public function addEmploymentHistory(Request $request, $staffId)
    {
        $validated = $request->validate([
            'employer' => 'required|string|max:200',
            'role' => 'required|string|max:120',
            'start_year' => 'required|integer|min:1950|max:' . now()->year,
            'end_year' => 'nullable|integer|min:1950|max:' . now()->year,
            'notes' => 'nullable|string|max:1000',
        ]);

        if (! empty($validated['end_year']) && $validated['end_year'] < $validated['start_year']) {
            return response()->json([
                'errors' => ['end_year' => ['The end year cannot be before the start year.']],
            ], 422);
        }

        $staff = Staff::where('school_id', $this->schoolId($request))->findOrFail($staffId);

        $history = $staff->employment_history ?? [];
        $history[] = $validated;

        $staff->update(['employment_history' => $history]);

        return response()->json([
            'message' => 'Employment history updated.',
            'employment_history' => $staff->fresh()->employment_history,
        ], 201);
    }
}
