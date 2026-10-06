<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelAsset;
use App\Models\HostelAssetIssue;
use App\Models\HostelAttendance;
use App\Models\HostelBed;
use App\Models\HostelDutyShift;
use App\Models\HostelIncident;
use App\Models\HostelInspection;
use App\Models\HostelLeaveRequest;
use App\Models\HostelMaintenanceRequest;
use App\Models\HostelMovement;
use App\Models\HostelRollcall;
use App\Models\HostelRollcallSession;
use App\Models\HostelRoom;
use App\Models\HostelSetting;
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
 * Daily hostel operations and care (TASK 17 §10–§18): roll call, leave and
 * movement, welfare, incidents, duty roster, maintenance, inspections and
 * issued items.
 *
 * Approval never stands in for an event: approved leave is not a departure,
 * and an unsubmitted roll call is not presence (rules 8, 10).
 */
class HostelOpsController extends Controller
{
    public function __construct(private HostelService $hostels)
    {
    }

    // ==================================================================
    // Roll call (§10)
    // ==================================================================

    public function sessions(): JsonResponse
    {
        return response()->json(['data' => HostelRollcallSession::where('school_id', $this->schoolId())->orderBy('time')->get()]);
    }

    public function storeSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hostel_id' => ['nullable', Rule::exists('hostels', 'id')->where('school_id', $this->schoolId())],
            'name' => 'required|string|max:40',
            'time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'days' => 'required|array|min:1', 'days.*' => 'integer|between:1,7',
            'grace_minutes' => 'nullable|integer|min:0|max:180',
        ]);

        return response()->json(['session' => HostelRollcallSession::create($data + ['school_id' => $this->schoolId(), 'is_active' => true])], 201);
    }

    /**
     * Open today's roll call: everyone expected, with anyone away on
     * approved leave already shown as such. Everyone else starts unconfirmed.
     */
    public function open(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'session_id' => ['required', Rule::exists('hostel_rollcall_sessions', 'id')->where('school_id', $schoolId)],
            'hostel_id' => ['required', Rule::exists('hostels', 'id')->where('school_id', $schoolId)],
            'date' => 'nullable|date',
        ]);
        $this->guard(fn () => $this->hostels->assertHostel($request->user(), (int) $data['hostel_id'], ['manager', 'warden', 'house_parent']));

        $date = $data['date'] ?? now()->toDateString();

        $rollcall = DB::transaction(function () use ($schoolId, $data, $date, $request) {
            $rc = HostelRollcall::firstOrCreate(
                ['session_id' => $data['session_id'], 'hostel_id' => $data['hostel_id'], 'date' => $date],
                ['school_id' => $schoolId, 'status' => 'open', 'opened_by' => $request->user()->id]
            );

            foreach (app(HostelController::class)->residentIds($schoolId, [(int) $data['hostel_id']]) as $studentId) {
                $away = $this->hostels->presence($studentId) === 'on_leave';

                HostelAttendance::firstOrCreate(
                    ['rollcall_id' => $rc->id, 'student_id' => $studentId],
                    ['school_id' => $schoolId, 'status' => $away ? 'on_leave' : 'unconfirmed']
                );
            }

            return $rc;
        });

        return response()->json($this->rollcallPayload($rollcall));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->rollcallPayload(HostelRollcall::where('school_id', $this->schoolId())->findOrFail($id)));
    }

    public function mark(Request $request, int $id): JsonResponse
    {
        $rc = HostelRollcall::where('school_id', $this->schoolId())->findOrFail($id);
        $this->guard(fn () => $this->hostels->assertHostel($request->user(), $rc->hostel_id, ['manager', 'warden', 'house_parent']));

        $data = $request->validate([
            'marks' => 'required|array|min:1',
            'marks.*.student_id' => 'required|integer',
            'marks.*.status' => 'required|in:present,absent,on_leave,school_activity,medical,not_expected,unconfirmed',
            'marks.*.note' => 'nullable|string|max:255',
            'correction_reason' => 'nullable|string|max:500',
        ]);

        // After submission, a change is a correction: managers only, with a reason.
        if ($rc->status === 'submitted') {
            $this->guard(fn () => $this->hostels->assertHostel($request->user(), $rc->hostel_id, ['manager']));
            abort_if(trim((string) ($data['correction_reason'] ?? '')) === '', 422, 'A submitted roll call is corrected only with a reason.');
        }

        foreach ($data['marks'] as $m) {
            $row = HostelAttendance::where('rollcall_id', $rc->id)->where('student_id', $m['student_id'])->first();

            if (! $row) {
                continue;
            }

            $row->update([
                'status' => $m['status'], 'note' => $m['note'] ?? null, 'marked_by' => $request->user()->id,
                'correction_reason' => $rc->status === 'submitted' ? $data['correction_reason'] : $row->correction_reason,
            ]);
        }

        if ($rc->status === 'submitted') {
            $this->hostels->audit($request->user(), 'hostel.rollcall_corrected', HostelRollcall::class, $rc->id, $data);
        }

        return response()->json($this->rollcallPayload($rc->fresh()));
    }

    /** Submitting is the record; absences raise the follow-up (rule 11). */
    public function submit(Request $request, int $id): JsonResponse
    {
        $rc = HostelRollcall::where('school_id', $this->schoolId())->where('status', 'open')->findOrFail($id);
        $this->guard(fn () => $this->hostels->assertHostel($request->user(), $rc->hostel_id, ['manager', 'warden', 'house_parent']));

        $rc->update(['status' => 'submitted', 'submitted_by' => $request->user()->id, 'submitted_at' => now()]);

        $missing = HostelAttendance::where('rollcall_id', $rc->id)->whereIn('status', ['absent', 'unconfirmed'])->with('student.user:id,name')->get();

        if ($missing->isNotEmpty()) {
            $managers = DB::table('hostel_staff_assignments')->where('hostel_id', $rc->hostel_id)->where('role', 'manager')->whereNull('ends_on')->pluck('user_id')->all()
                ?: DB::table('user_profiles')->where('school_id', $rc->school_id)->where('role', 'school_admin')->pluck('user_id')->all();
            InAppNotifier::send($rc->school_id, array_map('intval', $managers), 'hostel_rollcall', 'Students not accounted for', $missing->count().' student(s) were absent or unconfirmed at roll call. Follow up now.');
        }

        return response()->json($this->rollcallPayload($rc->fresh()) + ['not_accounted_for' => $missing->count()]);
    }

    private function rollcallPayload(HostelRollcall $rc): array
    {
        $rows = HostelAttendance::where('rollcall_id', $rc->id)->with('student.user:id,name')->get();

        return [
            'rollcall' => $rc,
            'students' => $rows->map(fn ($r) => ['student_id' => $r->student_id, 'name' => $r->student?->user?->name, 'status' => $r->status, 'note' => $r->note])->sortBy('name')->values(),
            'counts' => $rows->countBy('status'),
            'unmarked' => $rows->where('status', 'unconfirmed')->count(),
        ];
    }

    // ==================================================================
    // Leave and movement (§11)
    // ==================================================================

    public function leave(Request $request): JsonResponse
    {
        $rows = HostelLeaveRequest::where('school_id', $this->schoolId())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with('student.user:id,name')->orderByDesc('id')->limit(300)->get();

        $grace = HostelSetting::for($this->schoolId())->return_grace_minutes;

        return response()->json(['data' => $rows->map(fn ($l) => $l->toArray() + [
            'student_name' => $l->student?->user?->name,
            'overdue' => $l->status === 'departed' && $l->return_at->lt(now()->subMinutes($grace)),
        ])]);
    }

    public function storeLeave(Request $request): JsonResponse
    {
        $data = $this->validateLeave($request);

        return response()->json(['leave' => $this->createLeave($data, 'staff', $request->user()->id)], 201);
    }

    public function validateLeave(Request $request): array
    {
        return $request->validate([
            'student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $this->schoolId())],
            'leave_type' => 'required|in:weekend,medical,outing,family,other',
            'reason' => 'required|string|max:255',
            'depart_at' => 'required|date',
            'return_at' => 'required|date|after:depart_at',
            'destination' => 'nullable|string|max:255',
            'pickup_person' => 'nullable|string|max:120',
        ]);
    }

    public function createLeave(array $data, string $by, int $userId): HostelLeaveRequest
    {
        abort_unless(HostelAllocation::where('student_id', $data['student_id'])->where('status', 'active')->exists(), 409, 'Only a boarder with a bed can request hostel leave.');

        $needsConsent = in_array($data['leave_type'], (array) HostelSetting::for($this->schoolId())->consent_leave_types, true);
        $consent = ! $needsConsent ? 'not_required' : ($by === 'parent' ? 'given' : 'pending');

        return HostelLeaveRequest::create($data + [
            'school_id' => $this->schoolId(),
            'guardian_consent' => $consent,
            'requested_by_type' => $by,
            'requested_by' => $userId,
            'status' => $consent === 'pending' ? 'awaiting_consent' : 'submitted',
        ]);
    }

    public function recordConsent(Request $request, int $id): JsonResponse
    {
        $l = HostelLeaveRequest::where('school_id', $this->schoolId())->where('status', 'awaiting_consent')->findOrFail($id);
        $data = $request->validate(['given' => 'required|boolean', 'note' => 'required|string|max:255']);

        $l->update(['guardian_consent' => $data['given'] ? 'given' : 'refused', 'status' => $data['given'] ? 'submitted' : 'rejected', 'decision_note' => $data['note']]);
        $this->hostels->audit($request->user(), 'hostel.leave_consent_recorded', HostelLeaveRequest::class, $l->id, $data);

        return response()->json(['leave' => $l->fresh()]);
    }

    public function decideLeave(Request $request, int $id): JsonResponse
    {
        $l = HostelLeaveRequest::where('school_id', $this->schoolId())->where('status', 'submitted')->findOrFail($id);
        $data = $request->validate(['approve' => 'required|boolean', 'note' => 'nullable|string|max:255']);

        if ($hostel = $this->hostels->hostelOf($l->student_id)) {
            $this->guard(fn () => $this->hostels->assertHostel($request->user(), $hostel, ['manager', 'warden']));
        }

        $l->update(['status' => $data['approve'] ? 'approved' : 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'decision_note' => $data['note'] ?? null]);
        $this->notifyGuardians($l->student_id, 'Hostel leave '.($data['approve'] ? 'approved' : 'not approved'), 'Open the hostel page for the details of the leave request.');

        return response()->json(['leave' => $l->fresh()]);
    }

    /** The gate or hostel desk records the actual departure (rule 8). */
    public function depart(Request $request, int $id): JsonResponse
    {
        $l = HostelLeaveRequest::where('school_id', $this->schoolId())->where('status', 'approved')->findOrFail($id);
        $data = $request->validate(['collected_by' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:255']);

        $this->hostels->movement($l->school_id, $l->student_id, 'departure', $request->user(), ['leave_request_id' => $l->id] + $data);
        $l->update(['status' => 'departed']);
        $this->notifyGuardians($l->student_id, 'Left the hostel', 'Your child has signed out of the hostel on approved leave.');

        return response()->json(['leave' => $l->fresh()]);
    }

    public function returned(Request $request, int $id): JsonResponse
    {
        $l = HostelLeaveRequest::where('school_id', $this->schoolId())->where('status', 'departed')->findOrFail($id);
        $data = $request->validate(['notes' => 'nullable|string|max:255']);

        $this->hostels->movement($l->school_id, $l->student_id, 'return', $request->user(), ['leave_request_id' => $l->id] + $data);
        $l->update(['status' => 'returned']);
        $this->notifyGuardians($l->student_id, 'Back at the hostel', 'Your child has signed back into the hostel.');

        return response()->json(['leave' => $l->fresh()]);
    }

    public function movements(Request $request): JsonResponse
    {
        return response()->json(['data' => HostelMovement::where('school_id', $this->schoolId())
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->orderByDesc('occurred_at')->limit(300)->get()
            ->map(fn ($m) => $m->toArray() + ['student_name' => DB::table('students')->join('users', 'users.id', '=', 'students.user_id')->where('students.id', $m->student_id)->value('users.name')])]);
    }

    /** A correction keeps the original event and records why (§11). */
    public function correctMovement(Request $request, int $id): JsonResponse
    {
        $m = HostelMovement::where('school_id', $this->schoolId())->findOrFail($id);
        $data = $request->validate(['occurred_at' => 'required|date', 'reason' => 'required|string|min:3|max:255']);

        $fix = $this->hostels->movement($m->school_id, $m->student_id, $m->type, $request->user(), [
            'occurred_at' => $data['occurred_at'], 'corrects_id' => $m->id, 'correction_reason' => $data['reason'], 'leave_request_id' => $m->leave_request_id,
        ]);

        return response()->json(['movement' => $fix], 201);
    }

    // ==================================================================
    // Welfare (§14) and incidents (§15) — restricted
    // ==================================================================

    public function welfare(Request $request): JsonResponse
    {
        abort_unless($this->hostels->seesWelfare($request->user()), 403, 'Welfare records are restricted.');
        $this->hostels->audit($request->user(), 'hostel.welfare_viewed', HostelWelfareRecord::class, 0, []);

        return response()->json(['data' => HostelWelfareRecord::where('school_id', $this->schoolId())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn ($w) => $w->toArray() + ['student_name' => DB::table('students')->join('users', 'users.id', '=', 'students.user_id')->where('students.id', $w->student_id)->value('users.name')])]);
    }

    /** Any hostel staff member can record a concern; reading them is restricted. */
    public function storeWelfare(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        abort_if($this->hostels->hostelsFor($request->user()) === [] && ! $this->hostels->seesWelfare($request->user()), 403);

        $data = $request->validate([
            'student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'category' => 'required|in:welfare_check,student_concern,guardian_contact,referral,follow_up',
            'description' => 'required|string|min:5|max:4000',
            'action_taken' => 'nullable|string|max:4000',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'assigned_to' => ['nullable', Rule::exists('user_profiles', 'user_id')->where('school_id', $schoolId)],
            'follow_up_on' => 'nullable|date',
        ]);

        $row = HostelWelfareRecord::create($data + ['school_id' => $schoolId, 'recorded_by' => $request->user()->id, 'status' => 'open', 'priority' => $data['priority'] ?? 'normal']);
        $this->hostels->audit($request->user(), 'hostel.welfare_recorded', HostelWelfareRecord::class, $row->id, ['student_id' => $data['student_id'], 'category' => $data['category']]);

        if ($row->assigned_to) {
            InAppNotifier::send($schoolId, [(int) $row->assigned_to], 'hostel_welfare', 'Welfare follow-up assigned', 'A welfare record needs your follow-up. Open the hostel welfare list.');
        }

        return response()->json(['id' => $row->id], 201);
    }

    public function updateWelfare(Request $request, int $id): JsonResponse
    {
        abort_unless($this->hostels->seesWelfare($request->user()), 403);
        $row = HostelWelfareRecord::where('school_id', $this->schoolId())->findOrFail($id);
        $data = $request->validate(['action_taken' => 'sometimes|string|max:4000', 'follow_up_on' => 'sometimes|nullable|date', 'status' => 'sometimes|in:open,closed']);

        $row->update($data + (($data['status'] ?? null) === 'closed' ? ['closed_at' => now()] : []));
        $this->hostels->audit($request->user(), 'hostel.welfare_updated', HostelWelfareRecord::class, $row->id, array_keys($data));

        return response()->json(['record' => $row->fresh()]);
    }

    public function incidents(Request $request): JsonResponse
    {
        abort_unless($this->hostels->seesWelfare($request->user()), 403, 'Incident records are restricted.');

        return response()->json(['data' => HostelIncident::where('school_id', $this->schoolId())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('occurred_at')->limit(200)->get()
            ->map(fn ($i) => $i->only(['id', 'reference', 'category', 'occurred_at', 'location', 'status', 'guardian_notified_at']) + [
                'people' => DB::table('hostel_incident_people')->where('incident_id', $i->id)->count(),
            ])]);
    }

    public function showIncident(Request $request, int $id): JsonResponse
    {
        abort_unless($this->hostels->seesWelfare($request->user()), 403);
        $i = HostelIncident::where('school_id', $this->schoolId())->findOrFail($id);
        $this->hostels->audit($request->user(), 'hostel.incident_viewed', HostelIncident::class, $i->id, []);

        return response()->json([
            'incident' => $i,
            'people' => DB::table('hostel_incident_people as p')->leftJoin('students as s', 's.id', '=', 'p.student_id')->leftJoin('users as u', 'u.id', '=', DB::raw('COALESCE(s.user_id, p.user_id)'))
                ->where('p.incident_id', $i->id)->get(['p.role', 'u.name', 'p.student_id']),
            'history' => DB::table('hostel_incident_events')->where('incident_id', $i->id)->orderBy('id')->get(),
        ]);
    }

    /** A report, not a finding: what happened is kept apart from the outcome (§15). */
    public function storeIncident(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        abort_if($this->hostels->hostelsFor($request->user()) === [] && ! $this->hostels->seesWelfare($request->user()), 403);

        $data = $request->validate([
            'hostel_id' => ['nullable', Rule::exists('hostels', 'id')->where('school_id', $schoolId)],
            'occurred_at' => 'required|date',
            'location' => 'nullable|string|max:255',
            'category' => 'required|in:safety,property_damage,conflict,rule_breach,missing_property,welfare,unauthorised_absence,other',
            'description' => 'required|string|min:10|max:6000',
            'immediate_action' => 'nullable|string|max:4000',
            'people' => 'nullable|array|max:30',
            'people.*.student_id' => ['nullable', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'people.*.user_id' => 'nullable|integer',
            'people.*.role' => 'required_with:people|in:involved,affected,witness',
        ]);

        $incident = DB::transaction(function () use ($data, $schoolId, $request) {
            $n = HostelIncident::where('school_id', $schoolId)->count() + 1;
            $i = HostelIncident::create(collect($data)->except('people')->all() + [
                'school_id' => $schoolId, 'reference' => sprintf('HI-%s-%04d', now()->format('Y'), $n), 'reported_by' => $request->user()->id, 'status' => 'submitted',
            ]);

            foreach ($data['people'] ?? [] as $p) {
                DB::table('hostel_incident_people')->insert(['incident_id' => $i->id, 'student_id' => $p['student_id'] ?? null, 'user_id' => $p['user_id'] ?? null, 'role' => $p['role']]);
            }

            DB::table('hostel_incident_events')->insert(['incident_id' => $i->id, 'action' => 'submitted', 'user_id' => $request->user()->id, 'created_at' => now()]);

            return $i;
        });

        return response()->json(['id' => $incident->id, 'reference' => $incident->reference], 201);
    }

    /** Edits after submission need a reason and keep the history (§15). */
    public function updateIncident(Request $request, int $id): JsonResponse
    {
        abort_unless($this->hostels->seesWelfare($request->user()), 403);
        $i = HostelIncident::where('school_id', $this->schoolId())->findOrFail($id);

        $data = $request->validate([
            'status' => 'sometimes|in:under_review,follow_up_required,resolved,closed,reopened',
            'outcome' => 'sometimes|nullable|string|max:4000',
            'reviewer_id' => 'sometimes|nullable|integer',
            'guardian_notified' => 'sometimes|boolean',
            'reason' => 'required|string|min:3|max:500',
        ]);

        $changes = collect($data)->except(['reason', 'guardian_notified'])->all();
        if ($request->boolean('guardian_notified')) {
            $changes['guardian_notified_at'] = now();
        }

        $i->update($changes);
        DB::table('hostel_incident_events')->insert(['incident_id' => $i->id, 'action' => 'updated', 'reason' => $data['reason'], 'changes' => json_encode(array_keys($changes)), 'user_id' => $request->user()->id, 'created_at' => now()]);

        return response()->json(['incident' => $i->fresh()->only(['id', 'reference', 'status', 'guardian_notified_at'])]);
    }

    // ==================================================================
    // Duty roster (§16)
    // ==================================================================

    public function roster(Request $request): JsonResponse
    {
        $from = $request->input('from', now()->startOfWeek()->toDateString());

        return response()->json(['data' => HostelDutyShift::where('school_id', $this->schoolId())->whereBetween('date', [$from, now()->parse($from)->addDays(13)->toDateString()])
            ->orderBy('date')->get()->map(fn ($s) => $s->toArray() + ['name' => DB::table('users')->where('id', $s->user_id)->value('name')])]);
    }

    public function storeShift(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'hostel_id' => ['required', Rule::exists('hostels', 'id')->where('school_id', $schoolId)],
            'user_id' => ['required', Rule::exists('user_profiles', 'user_id')->where('school_id', $schoolId)],
            'date' => 'required|date', 'shift' => 'required|in:morning,afternoon,evening,night',
            'substitute_for' => 'nullable|integer', 'handover_notes' => 'nullable|string|max:2000',
        ]);
        $this->guard(fn () => $this->hostels->assertHostel($request->user(), (int) $data['hostel_id'], ['manager']));

        $clash = HostelDutyShift::where('user_id', $data['user_id'])->where('date', $data['date'])->where('shift', $data['shift'])->where('hostel_id', '!=', $data['hostel_id'])->exists();
        $shift = HostelDutyShift::updateOrCreate(
            ['hostel_id' => $data['hostel_id'], 'user_id' => $data['user_id'], 'date' => $data['date'], 'shift' => $data['shift']],
            ['school_id' => $schoolId, 'substitute_for' => $data['substitute_for'] ?? null, 'handover_notes' => $data['handover_notes'] ?? null]
        );

        return response()->json(['shift' => $shift, 'warning' => $clash ? 'This person is also on duty in another hostel at the same time.' : null], 201);
    }

    public function publishRoster(Request $request): JsonResponse
    {
        $data = $request->validate(['hostel_id' => ['required', Rule::exists('hostels', 'id')->where('school_id', $this->schoolId())], 'from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $this->guard(fn () => $this->hostels->assertHostel($request->user(), (int) $data['hostel_id'], ['manager']));

        $shifts = HostelDutyShift::where('hostel_id', $data['hostel_id'])->whereBetween('date', [$data['from'], $data['to']]);
        $users = (clone $shifts)->pluck('user_id')->unique()->all();
        $shifts->update(['published' => true]);
        InAppNotifier::send($this->schoolId(), array_map('intval', $users), 'hostel_roster', 'Duty roster published', 'Your hostel duties for '.$data['from'].' to '.$data['to'].' are published.');

        return response()->json(['message' => 'Published to '.count($users).' staff member(s).']);
    }

    // ==================================================================
    // Maintenance (§17) and inspections
    // ==================================================================

    public function maintenance(Request $request): JsonResponse
    {
        $scope = $this->hostels->hostelsFor($request->user(), ['manager', 'warden', 'house_parent', 'matron']);

        return response()->json(['data' => HostelMaintenanceRequest::where('school_id', $this->schoolId())
            ->when($scope !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('hostel_id', $scope ?: [0])->orWhere('assigned_to', $request->user()->id)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')->limit(300)->get()
            ->map(fn ($m) => $m->toArray() + ['overdue' => ! in_array($m->status, ['completed', 'verified', 'cancelled'], true) && $m->created_at->lt(now()->subDays($m->priority === 'urgent' ? 1 : ($m->priority === 'high' ? 3 : 14)))])]);
    }

    public function storeMaintenance(Request $request): JsonResponse
    {
        $data = $this->validateMaintenance($request);

        return response()->json(['request' => $this->createMaintenance($data, $request->user()->id)], 201);
    }

    public function validateMaintenance(Request $request): array
    {
        $schoolId = $this->schoolId();

        return $request->validate([
            'hostel_id' => ['required', Rule::exists('hostels', 'id')->where('school_id', $schoolId)],
            'room_id' => ['nullable', Rule::exists('hostel_rooms', 'id')->where('school_id', $schoolId)],
            'bed_id' => ['nullable', Rule::exists('hostel_beds', 'id')->where('school_id', $schoolId)],
            'asset_id' => ['nullable', Rule::exists('hostel_assets', 'id')->where('school_id', $schoolId)],
            'category' => 'required|in:plumbing,electrical,furniture,bedding,building,security,cleaning,other',
            'description' => 'required|string|min:5|max:2000',
            'priority' => 'nullable|in:low,normal,high,urgent',
        ]);
    }

    public function createMaintenance(array $data, int $userId): HostelMaintenanceRequest
    {
        $schoolId = $this->schoolId();
        $n = HostelMaintenanceRequest::where('school_id', $schoolId)->count() + 1;

        return HostelMaintenanceRequest::create($data + ['school_id' => $schoolId, 'reference' => sprintf('HM-%s-%04d', now()->format('Y'), $n), 'reported_by' => $userId, 'status' => 'reported', 'priority' => $data['priority'] ?? 'normal']);
    }

    /**
     * A request does not take a bed out of service by itself; a manager says
     * so (§17), and only verification puts it back.
     */
    public function updateMaintenance(Request $request, int $id): JsonResponse
    {
        $m = HostelMaintenanceRequest::where('school_id', $this->schoolId())->findOrFail($id);
        $data = $request->validate([
            'status' => 'sometimes|in:acknowledged,assigned,in_progress,awaiting_parts,completed,cancelled',
            'assigned_to' => ['sometimes', 'nullable', Rule::exists('user_profiles', 'user_id')->where('school_id', $this->schoolId())],
            'work_notes' => 'sometimes|nullable|string|max:2000',
            'take_out_of_service' => 'sometimes|boolean',
        ]);

        $isAssignee = $m->assigned_to === $request->user()->id;
        if (! $isAssignee) {
            $this->guard(fn () => $this->hostels->assertHostel($request->user(), $m->hostel_id, ['manager', 'warden', 'house_parent']));
        }

        if ($request->boolean('take_out_of_service')) {
            $this->guard(fn () => $this->hostels->assertHostel($request->user(), $m->hostel_id, ['manager']));
            if ($m->bed_id) {
                abort_if(HostelAllocation::where('bed_id', $m->bed_id)->where('status', 'active')->exists(), 409, 'Move the student out before taking the bed out of service.');
                HostelBed::whereKey($m->bed_id)->update(['status' => 'out_of_service', 'status_note' => "Maintenance {$m->reference}", 'status_changed_at' => now()]);
            } elseif ($m->room_id) {
                HostelRoom::whereKey($m->room_id)->update(['status' => 'out_of_service']);
            }
            $data['took_out_of_service'] = true;
        }

        $m->update(collect($data)->except('take_out_of_service')->all() + (($data['status'] ?? null) === 'completed' ? ['completed_at' => now()] : []));

        if (isset($data['assigned_to']) && $data['assigned_to']) {
            InAppNotifier::send($m->school_id, [(int) $data['assigned_to']], 'hostel_maintenance', 'Maintenance job assigned', "Job {$m->reference} is assigned to you.");
        }

        return response()->json(['request' => $m->fresh()]);
    }

    public function verifyMaintenance(Request $request, int $id): JsonResponse
    {
        $m = HostelMaintenanceRequest::where('school_id', $this->schoolId())->where('status', 'completed')->findOrFail($id);
        $this->guard(fn () => $this->hostels->assertHostel($request->user(), $m->hostel_id, ['manager']));

        DB::transaction(function () use ($m, $request) {
            $m->update(['status' => 'verified', 'verified_by' => $request->user()->id, 'verified_at' => now()]);

            if ($m->took_out_of_service) {
                if ($m->bed_id) {
                    HostelBed::whereKey($m->bed_id)->update(['status' => 'available', 'status_note' => "Back in service after {$m->reference}", 'status_changed_at' => now()]);
                } elseif ($m->room_id) {
                    HostelRoom::whereKey($m->room_id)->update(['status' => 'available']);
                }
            }
        });

        return response()->json(['request' => $m->fresh()]);
    }

    public function inspect(Request $request, int $roomId): JsonResponse
    {
        $room = HostelRoom::where('school_id', $this->schoolId())->findOrFail($roomId);
        $this->guard(fn () => $this->hostels->assertHostel($request->user(), $room->hostel_id, ['manager', 'warden', 'house_parent']));
        $data = $request->validate(['checklist' => 'nullable|array', 'result' => 'required|in:pass,attention,fail', 'notes' => 'nullable|string|max:2000']);

        $row = HostelInspection::create($data + ['school_id' => $room->school_id, 'room_id' => $room->id, 'inspected_by' => $request->user()->id, 'inspected_at' => now()]);
        $room->update(['last_inspected_at' => now()]);

        return response()->json(['inspection' => $row], 201);
    }

    public function inspections(Request $request): JsonResponse
    {
        return response()->json(['data' => HostelInspection::where('school_id', $this->schoolId())
            ->when($request->filled('room_id'), fn ($q) => $q->where('room_id', $request->integer('room_id')))->orderByDesc('inspected_at')->limit(200)->get()]);
    }

    // ==================================================================
    // Inventory and issued items (§18)
    // ==================================================================

    public function assets(): JsonResponse
    {
        $schoolId = $this->schoolId();

        // Who holds each item now, so a return can be recorded against the right issue.
        $open = HostelAssetIssue::where('hostel_asset_issues.school_id', $schoolId)->where('hostel_asset_issues.status', 'issued')
            ->join('students', 'students.id', '=', 'hostel_asset_issues.student_id')->join('users', 'users.id', '=', 'students.user_id')
            ->get(['hostel_asset_issues.id', 'hostel_asset_issues.asset_id', 'hostel_asset_issues.quantity', 'hostel_asset_issues.issued_at', 'users.name as student_name'])
            ->groupBy('asset_id');

        return response()->json(['data' => HostelAsset::where('school_id', $schoolId)->orderBy('name')->get()
            ->map(fn ($a) => $a->toArray() + [
                'issued' => (int) HostelAssetIssue::where('asset_id', $a->id)->where('status', 'issued')->sum('quantity'),
                'open_issues' => $open->get($a->id, collect())->values(),
            ])]);
    }

    public function storeAsset(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'hostel_id' => ['nullable', Rule::exists('hostels', 'id')->where('school_id', $schoolId)],
            'room_id' => ['nullable', Rule::exists('hostel_rooms', 'id')->where('school_id', $schoolId)],
            'name' => 'required|string|max:120', 'code' => 'nullable|string|max:40',
            'quantity' => 'required|integer|min:1|max:100000', 'condition' => 'nullable|in:good,fair,poor,damaged', 'issuable' => 'boolean',
        ]);

        return response()->json(['asset' => HostelAsset::create($data + ['school_id' => $schoolId, 'condition' => $data['condition'] ?? 'good'])], 201);
    }

    public function issue(Request $request, int $assetId): JsonResponse
    {
        $asset = HostelAsset::where('school_id', $this->schoolId())->where('issuable', true)->findOrFail($assetId);
        $data = $request->validate(['student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $this->schoolId())], 'quantity' => 'nullable|integer|min:1|max:50', 'condition_out' => 'nullable|in:good,fair,poor']);

        $out = (int) HostelAssetIssue::where('asset_id', $asset->id)->where('status', 'issued')->sum('quantity');
        $qty = $data['quantity'] ?? 1;
        abort_if($out + $qty > $asset->quantity, 409, 'Not enough of this item in stock.');

        return response()->json(['issue' => HostelAssetIssue::create(['school_id' => $asset->school_id, 'asset_id' => $asset->id, 'student_id' => $data['student_id'], 'quantity' => $qty, 'issued_at' => now(), 'condition_out' => $data['condition_out'] ?? 'good', 'status' => 'issued', 'issued_by' => $request->user()->id])], 201);
    }

    /** Returned, lost or damaged. A charge, if any, is raised in Finance, not here. */
    public function returnIssue(Request $request, int $issueId): JsonResponse
    {
        $row = HostelAssetIssue::where('school_id', $this->schoolId())->where('status', 'issued')->findOrFail($issueId);
        $data = $request->validate(['status' => 'required|in:returned,lost,damaged', 'condition_in' => 'nullable|in:good,fair,poor,damaged']);

        $row->update($data + ['returned_at' => $data['status'] === 'lost' ? null : now(), 'received_by' => $request->user()->id]);

        return response()->json(['issue' => $row->fresh()]);
    }

    // ==================================================================

    private function notifyGuardians(int $studentId, string $title, string $body): void
    {
        $users = DB::table('student_guardian')->join('guardians', 'guardians.id', '=', 'student_guardian.guardian_id')->where('student_guardian.student_id', $studentId)->pluck('guardians.user_id')->all();
        InAppNotifier::send($this->schoolId(), array_map('intval', $users), 'hostel', $title, $body);
    }

    private function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (AuthorisationFailure $e) {
            abort(response()->json(['message' => $e->getMessage()], 403));
        }
    }
}
