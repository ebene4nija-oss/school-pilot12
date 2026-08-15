<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\SessionRolloverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * End-of-session rollover: preview, then commit.
 *
 * The old `POST /students/promote` still works and is still the right call for
 * "move these four children to JSS 2 Silver". This is the September routine —
 * the whole school at once, with graduation and exits handled rather than left
 * as the one case the endpoint could not express.
 */
class SessionRolloverController extends Controller
{
    public function __construct(private SessionRolloverService $rollover)
    {
    }

    private function schoolId(Request $request): ?int
    {
        return $request->user()?->userProfile?->school_id;
    }

    /**
     * What would happen, without anything happening.
     */
    public function preview(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $validator = Validator::make($request->all(), [
            'from_session_id' => [
                'required',
                Rule::exists('academic_sessions', 'id')->where('school_id', $schoolId),
            ],
            'class_id' => [
                'nullable',
                Rule::exists('classes', 'id')->where('school_id', $schoolId),
            ],
            'pass_mark' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $fromSession = AcademicSession::where('school_id', $schoolId)
            ->findOrFail($request->from_session_id);

        $preview = $this->rollover->preview(
            $schoolId,
            $fromSession,
            $request->input('class_id'),
            (float) $request->input('pass_mark', 40),
        );

        // Arm choices for every class the preview proposes, so the screen can
        // offer streaming without a request per row.
        $classIds = array_values(array_unique(array_filter(
            array_merge(
                array_column($preview['students'], 'current_class_id'),
                array_column($preview['students'], 'proposed_class_id'),
            )
        )));

        $preview['arms_by_class'] = $this->rollover->armsFor($schoolId, $classIds);

        return response()->json($preview);
    }

    /**
     * Apply the decisions.
     */
    public function commit(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $validator = Validator::make($request->all(), [
            'from_session_id' => [
                'required',
                Rule::exists('academic_sessions', 'id')->where('school_id', $schoolId),
            ],
            'to_session_id' => [
                'required',
                'different:from_session_id',
                Rule::exists('academic_sessions', 'id')->where('school_id', $schoolId),
            ],
            'decisions' => 'required|array|min:1|max:2000',
            'decisions.*.student_id' => 'required|integer',
            'decisions.*.action' => 'required|in:promote,repeat,graduate,transfer_out,withdraw',
            'decisions.*.target_class_id' => [
                'nullable',
                Rule::exists('classes', 'id')->where('school_id', $schoolId),
            ],
            'decisions.*.target_arm_id' => [
                'nullable',
                Rule::exists('arms', 'id')->where('school_id', $schoolId),
            ],
            'decisions.*.destination_school' => 'nullable|string|max:255',
            'decisions.*.remarks' => 'nullable|string|max:1000',
        ]);

        $validator->after(function ($validator) use ($request) {
            foreach ((array) $request->input('decisions', []) as $i => $decision) {
                $action = $decision['action'] ?? null;
                $classId = $decision['target_class_id'] ?? null;
                $armId = $decision['target_arm_id'] ?? null;

                // A promotion with no destination is the bug that made
                // graduating a cohort impossible; now it is either a
                // destination or an explicit `graduate`.
                if ($action === 'promote' && ! $classId) {
                    $validator->errors()->add(
                        "decisions.{$i}.target_class_id",
                        'A class to promote into is required.'
                    );
                }

                // An arm from a different class silently re-streams a child
                // into a class they are not in.
                if ($armId && $classId) {
                    $belongs = Arm::where('id', $armId)->where('class_id', $classId)->exists();

                    if (! $belongs) {
                        $validator->errors()->add(
                            "decisions.{$i}.target_arm_id",
                            'That arm does not belong to the target class.'
                        );
                    }
                }
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $fromSession = AcademicSession::where('school_id', $schoolId)->findOrFail($request->from_session_id);
        $toSession = AcademicSession::where('school_id', $schoolId)->findOrFail($request->to_session_id);

        $result = $this->rollover->commit(
            $schoolId,
            $fromSession,
            $toSession,
            $request->input('decisions'),
            $request->user()->id,
        );

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'session.rollover',
            'auditable_type' => AcademicSession::class,
            'auditable_id' => $toSession->id,
            'old_values' => ['from_session_id' => $fromSession->id],
            'new_values' => [
                'to_session_id' => $toSession->id,
                'applied' => count($result['applied']),
                'skipped' => count($result['skipped']),
                'outcomes' => array_count_values(array_column($result['applied'], 'outcome')),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => count($result['applied']) . ' student(s) rolled over into ' . $toSession->name . '.',
            'applied_count' => count($result['applied']),
            'applied' => $result['applied'],
            'skipped' => $result['skipped'],
        ]);
    }

    /**
     * One student leaving mid-session — the common case that is not a
     * September rollover: a family relocates in February.
     */
    public function exitStudent(Request $request, $id)
    {
        $schoolId = $this->schoolId($request);

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:transfer_out,withdraw,graduate',
            'destination_school' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:1000',
            'left_on' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $student = Student::where('school_id', $schoolId)->findOrFail($id);

        $outcome = match ($request->action) {
            'transfer_out' => 'transferred_out',
            'withdraw' => 'withdrawn',
            'graduate' => 'graduated',
        };

        DB::transaction(function () use ($student, $request, $outcome, $schoolId) {
            StudentEnrollment::where('student_id', $student->id)
                ->where('status', 'active')
                ->get()
                ->each(fn ($enrollment) => $enrollment->update([
                    'status' => 'closed',
                    'outcome' => $outcome,
                    'closed_on' => $request->input('left_on', now()->toDateString()),
                    'destination_school' => $request->destination_school,
                    'outcome_remarks' => $request->remarks,
                    'recorded_by' => $request->user()->id,
                ]));

            $student->update([
                'status' => match ($outcome) {
                    'graduated' => 'graduated',
                    'transferred_out' => 'transferred',
                    'withdrawn' => 'withdrawn',
                },
            ]);

            AuditLog::create([
                'school_id' => $schoolId,
                'user_id' => $request->user()->id,
                'action' => 'student.' . $outcome,
                'auditable_type' => Student::class,
                'auditable_id' => $student->id,
                'new_values' => [
                    'outcome' => $outcome,
                    'destination_school' => $request->destination_school,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->json([
            'message' => 'Student recorded as ' . str_replace('_', ' ', $outcome) . '.',
            'student_id' => $student->id,
            'status' => $student->fresh()->status,
        ]);
    }

    /**
     * A student's placement session by session — the transcript spine.
     */
    public function enrollments(Request $request, $id)
    {
        $schoolId = $this->schoolId($request);

        $student = Student::where('school_id', $schoolId)->findOrFail($id);

        $enrollments = StudentEnrollment::where('student_id', $student->id)
            ->with(['session:id,name,start_date', 'schoolClass:id,name', 'arm:id,name'])
            ->get()
            ->sortByDesc(fn ($e) => $e->session->start_date ?? $e->created_at)
            ->values()
            ->map(fn ($e) => [
                'id' => $e->id,
                'session' => $e->session->name ?? null,
                'class' => $e->schoolClass->name ?? null,
                'arm' => $e->arm->name ?? null,
                'status' => $e->status,
                'outcome' => $e->outcome,
                'enrolled_on' => $e->enrolled_on?->toDateString(),
                'closed_on' => $e->closed_on?->toDateString(),
                'destination_school' => $e->destination_school,
                'remarks' => $e->outcome_remarks,
            ]);

        return response()->json([
            'student_id' => $student->id,
            'current_status' => $student->status,
            'enrollments' => $enrollments,
        ]);
    }
}
