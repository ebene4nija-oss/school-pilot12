<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\Staff;
use App\Models\StaffLeaveSetting;
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
        $schoolId = $this->schoolId();

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

        $policy = StaffLeaveSetting::for($schoolId);
        $role = $staff->user?->userProfile?->role;

        if (! $policy->allows($role)) {
            return response()->json([
                'error' => 'Your school does not take leave requests from '.(StaffLeaveSetting::ROLES[$role] ?? 'this role').' in SchoolPilot. Ask the school office.',
            ], 403);
        }

        $start = Carbon::parse($validated['start_date']);
        $end = Carbon::parse($validated['end_date']);
        $days = $start->diffInDays($end) + 1;

        // Over the allowance: refused only where the school says so;
        // otherwise the approver sees the figures and decides.
        $allowance = $staff->allowanceFor($validated['leave_type']);

        if ($policy->enforce_allowance && $allowance !== null
            && $staff->daysTakenThisYear($validated['leave_type']) + $days > $allowance) {
            $left = max(0, $allowance - $staff->daysTakenThisYear($validated['leave_type']));

            return response()->json([
                'error' => "That is more than your {$validated['leave_type']} leave allows: {$left} day(s) left this year.",
            ], 422);
        }

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

    /** The school's staff leave policy. Staff read it to know their allowance. */
    public function leaveSettings()
    {
        $settings = StaffLeaveSetting::for($this->schoolId());

        return response()->json([
            'leave_roles' => $settings->leave_roles ?? array_keys(StaffLeaveSetting::ROLES),
            'default_allowances' => (object) ($settings->default_allowances ?? []),
            'enforce_allowance' => (bool) $settings->enforce_allowance,
            'roles' => StaffLeaveSetting::ROLES,
            'types' => LeaveRequest::TYPES,
        ] + collect(LeaveRequest::TYPES)->mapWithKeys(fn ($t) => ["days_{$t}" => $settings->defaultFor($t)])->all());
    }

    public function updateLeaveSettings(Request $request)
    {
        $rules = [
            'leave_roles' => 'sometimes|array',
            'leave_roles.*' => ['string', Rule::in(array_keys(StaffLeaveSetting::ROLES))],
            'enforce_allowance' => 'sometimes|boolean',
        ];

        // Days per type arrive as flat fields (days_annual, days_sick, ...)
        // so the settings page can show one number box per type.
        foreach (LeaveRequest::TYPES as $type) {
            $rules["days_{$type}"] = 'sometimes|nullable|integer|min:0|max:365';
        }

        $data = $request->validate($rules);
        $schoolId = $this->schoolId();

        $settings = StaffLeaveSetting::withoutGlobalScopes()->firstOrNew(['school_id' => $schoolId]);
        $old = $settings->exists ? $settings->toArray() : null;

        if (array_key_exists('leave_roles', $data)) {
            $settings->leave_roles = array_values(array_unique($data['leave_roles']));
        } elseif (! $settings->exists) {
            $settings->leave_roles = array_keys(StaffLeaveSetting::ROLES);
        }

        if (array_key_exists('enforce_allowance', $data)) {
            $settings->enforce_allowance = $data['enforce_allowance'];
        }

        $allowances = $settings->default_allowances ?? [];
        foreach (LeaveRequest::TYPES as $type) {
            if (array_key_exists("days_{$type}", $data)) {
                if ($data["days_{$type}"] === null) {
                    unset($allowances[$type]);
                } else {
                    $allowances[$type] = (int) $data["days_{$type}"];
                }
            }
        }
        $settings->default_allowances = $allowances;
        $settings->save();

        \App\Models\AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'hr.leave_settings_updated',
            'auditable_type' => StaffLeaveSetting::class,
            'auditable_id' => $settings->id,
            'old_values' => $old,
            'new_values' => $settings->toArray(),
        ]);

        return $this->leaveSettings();
    }

    /** My own requests, or the whole school's for an admin. */
    public function listLeave(Request $request)
    {
        $schoolId = $this->schoolId();

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
        $schoolId = $this->schoolId();

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
        $schoolId = $this->schoolId();

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

        $staff = Staff::where('school_id', $this->schoolId())->findOrFail($staffId);
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
        $schoolId = $this->schoolId();
        // `me`: a member of staff reading their own record without knowing its id.
        $staff = $staffId === 'me'
            ? ($this->currentStaff($request)?->load('user:id,name') ?? abort(404, 'No staff record found for this user.'))
            : Staff::where('school_id', $schoolId)->with('user:id,name')->findOrFail($staffId);

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

        $staff = Staff::where('school_id', $this->schoolId())->findOrFail($staffId);

        $history = $staff->employment_history ?? [];
        $history[] = $validated;

        $staff->update(['employment_history' => $history]);

        return response()->json([
            'message' => 'Employment history updated.',
            'employment_history' => $staff->fresh()->employment_history,
        ], 201);
    }
}
