<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRefusal;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CbtAttempt;
use App\Models\CbtAttemptAnswer;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\CbtQuestionVersion;
use App\Models\CbtRegradeJob;
use App\Models\CbtResultRevision;
use App\Models\CbtSetting;
use App\Models\QuestionBankItem;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Cbt\CbtRegradeService;
use App\Services\CbtExamService;
use App\Services\Results\AuthorisationFailure;
use App\Services\Sections\HeadResolver;
use App\Support\InAppNotifier;
use App\Support\Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CBT oversight (CBT instructions §5.5, §5.6, §9, §10.6, §11): approval,
 * scheduling checks, the live monitor and its interventions, analysis,
 * question versions and regrades.
 *
 * Every intervention needs a reason and is logged in the attempt's own
 * append-only event log and the audit log.
 */
class CbtOversightController extends Controller
{
    public function __construct(
        private CbtExamService $exams,
        private CbtRegradeService $regrades,
        private HeadResolver $heads,
    ) {
    }

    // ==================================================================
    // Settings and approval (§5.6)
    // ==================================================================

    public function settings(): JsonResponse
    {
        return response()->json(CbtSetting::for($this->schoolId()));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_approval_required' => 'sometimes|boolean',
            'trusted_teacher_ids' => 'sometimes|array',
            'trusted_teacher_ids.*' => 'integer',
            'min_preparation_days' => 'sometimes|integer|min:0|max:30',
            'disconnect_after_seconds' => 'sometimes|integer|min:30|max:900',
        ]);

        $s = CbtSetting::withoutGlobalScopes()->firstOrNew(['school_id' => $this->schoolId()]);
        $s->fill($data)->save();
        $this->audit($request, 'cbt.settings_updated', CbtSetting::class, $s->id, $data);

        return response()->json($s);
    }

    public function submitForApproval(Request $request, int $id): JsonResponse
    {
        $exam = $this->exam($id);
        $this->authorize('manage', $exam);

        if ($exam->status !== 'draft') {
            return response()->json(['message' => 'Only a draft exam is submitted for approval.'], 409);
        }

        if (CbtExamQuestion::where('exam_id', $exam->id)->doesntExist()) {
            return response()->json(['message' => 'Attach questions before submitting.'], 422);
        }

        $exam->update(['approval_status' => 'pending', 'approval_note' => null]);
        $this->exams_log($exam, 'submitted_for_approval', $request->user());

        $approvers = $this->approvers($exam);
        InAppNotifier::send($exam->school_id, $approvers ?: DB::table('user_profiles')->where('school_id', $exam->school_id)->where('role', 'school_admin')->pluck('user_id')->all(), 'cbt_approval', 'Exam awaiting approval', "\"{$exam->title}\" is waiting for your approval.");

        return response()->json(['message' => 'Submitted for approval.', 'exam' => $exam->fresh()]);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $exam = $this->exam($id);
        $data = $request->validate(['approve' => 'required|boolean', 'note' => 'nullable|string|max:1000']);
        $user = $request->user();

        if ($exam->approval_status !== 'pending') {
            return response()->json(['message' => 'This exam is not awaiting approval.'], 409);
        }

        $approvers = $this->approvers($exam);
        $isAdmin = $user->userProfile?->role === 'school_admin';
        abort_unless(in_array($user->id, $approvers, true) || $isAdmin, 403, 'Only the subject\'s head of department or an admin approves exams.');
        abort_if((int) $exam->created_by === $user->id && ! $isAdmin, 403, 'An exam is approved by someone other than its author.');

        if (! $data['approve'] && trim((string) ($data['note'] ?? '')) === '') {
            return response()->json(['message' => 'Say what needs changing.'], 422);
        }

        $exam->update([
            'approval_status' => $data['approve'] ? 'approved' : 'rejected',
            'approval_note' => $data['note'] ?? null,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);
        $this->exams_log($exam, $data['approve'] ? 'approved' : 'rejected', $user, $data['note'] ?? null);

        if ($exam->created_by) {
            InAppNotifier::send($exam->school_id, [(int) $exam->created_by], 'cbt_approval', $data['approve'] ? 'Exam approved' : 'Exam returned', "\"{$exam->title}\" was ".($data['approve'] ? 'approved and can be scheduled.' : 'returned with comments.'));
        }

        return response()->json(['exam' => $exam->fresh()]);
    }

    /** HODs of the subject's department for the exam's class level. */
    private function approvers(CbtExam $exam): array
    {
        $department = DB::table('subjects')->where('id', $exam->subject_id)->value('department');
        $class = $exam->class_id ? SchoolClass::withoutGlobalScopes()->find($exam->class_id) : null;

        return $department && $class
            ? $this->heads->holders($exam->school_id, 'hod', $class->id, Sections::levelGroupOf($class->level_category), null, $department)
            : [];
    }

    /** Whether this exam may be published under the school's approval policy. */
    public static function approvalBlock(CbtExam $exam, User $publisher): ?string
    {
        $settings = CbtSetting::for((int) $exam->school_id);

        if (! $settings->exam_approval_required || $exam->approval_status === 'approved') {
            return null;
        }

        if ($publisher->userProfile?->role === 'school_admin' || in_array((int) $exam->created_by, array_map('intval', (array) $settings->trusted_teacher_ids), true)) {
            return null;
        }

        return 'This school approves exams before they are scheduled. Submit it for approval first.';
    }

    // ==================================================================
    // Scheduling checks (§5.5)
    // ==================================================================

    /** Warnings, not blocks: an admin may proceed, and the reason is logged. */
    public function checks(Request $request, int $id): JsonResponse
    {
        $exam = $this->exam($id);
        $this->authorize('view', $exam);

        return response()->json(['warnings' => self::scheduleWarnings($exam)]);
    }

    /** @return array<int, string> */
    public static function scheduleWarnings(CbtExam $exam): array
    {
        $warnings = [];
        $links = CbtExamQuestion::where('exam_id', $exam->id)->with('question')->get();

        if ($links->isEmpty()) {
            $warnings[] = 'The exam has no questions.';
        }

        $total = $links->sum(fn ($l) => $l->effectiveMarks($l->question));
        if ((float) $exam->total_marks > 0 && abs($total - (float) $exam->total_marks) > 0.01) {
            $warnings[] = "The questions add up to {$total} marks, not the {$exam->total_marks} set on the exam.";
        }

        if ($exam->opens_at && $exam->class_id) {
            $end = $exam->closes_at ?? $exam->opens_at->copy()->addMinutes($exam->duration_minutes);

            $clash = CbtExam::withoutGlobalScopes()->where('school_id', $exam->school_id)->where('class_id', $exam->class_id)
                ->where('id', '!=', $exam->id)->whereIn('status', ['published', 'draft'])
                ->whereNotNull('opens_at')->where('opens_at', '<', $end)
                ->where(fn ($q) => $q->where('closes_at', '>', $exam->opens_at)->orWhereNull('closes_at'))
                ->pluck('title');

            foreach ($clash as $title) {
                $warnings[] = "The same class has \"{$title}\" at an overlapping time.";
            }

            $inTerm = Term::withoutGlobalScopes()->where('school_id', $exam->school_id)
                ->where('start_date', '<=', $exam->opens_at->toDateString().' 23:59:59')
                ->where('end_date', '>=', $exam->opens_at->toDateString())->exists();

            if (! $inTerm) {
                $warnings[] = 'The exam opens outside the academic calendar\'s terms.';
            }

            $prep = CbtSetting::for((int) $exam->school_id)->min_preparation_days;
            if ($prep > 0 && $exam->opens_at->lt(now()->addDays($prep))) {
                $warnings[] = "Students get less than the school's {$prep} day(s) of notice.";
            }
        }

        return $warnings;
    }

    // ==================================================================
    // Live monitor and interventions (§9)
    // ==================================================================

    public function monitor(Request $request, int $id): JsonResponse
    {
        $exam = $this->exam($id);
        $this->authorize('view', $exam);
        $threshold = CbtSetting::for($exam->school_id)->disconnect_after_seconds;

        $roster = Student::where('school_id', $exam->school_id)->where('class_id', $exam->class_id)->where('status', 'active')->with('user:id,name')->get();
        $attempts = CbtAttempt::where('exam_id', $exam->id)->whereNotIn('status', ['voided'])->orderByDesc('attempt_number')->get()->unique('student_id')->keyBy('student_id');
        $lastSeen = DB::table('cbt_attempt_events')->whereIn('attempt_id', $attempts->pluck('id'))->selectRaw('attempt_id, MAX(occurred_at) as seen')->groupBy('attempt_id')->pluck('seen', 'attempt_id');
        $answered = CbtAttemptAnswer::whereIn('attempt_id', $attempts->pluck('id'))->whereNotNull('response')->selectRaw('attempt_id, COUNT(*) as n')->groupBy('attempt_id')->pluck('n', 'attempt_id');

        $rows = $roster->map(function ($s) use ($attempts, $lastSeen, $answered, $threshold) {
            $a = $attempts->get($s->id);
            $seen = $a ? ($lastSeen[$a->id] ?? $a->updated_at) : null;

            $state = match (true) {
                ! $a => 'not_started',
                in_array($a->status, ['submitted', 'graded', 'expired'], true) => 'submitted',
                $a->status === 'in_progress' && $seen && now()->diffInSeconds($seen, true) > $threshold => 'disconnected',
                default => 'in_progress',
            };

            return [
                'student_id' => $s->id,
                'name' => $s->user?->name,
                'admission_number' => $s->admission_number,
                'state' => $state,
                'attempt_id' => $a?->id,
                'answered' => $a ? (int) ($answered[$a->id] ?? 0) : 0,
                'questions' => $a ? count($a->question_order ?? []) : 0,
                'deadline' => $a?->server_deadline_at?->toIso8601String(),
                'seconds_left' => $a && $a->status === 'in_progress' && $a->server_deadline_at ? max(0, now()->diffInSeconds($a->server_deadline_at, false)) : null,
                'extra_time_minutes' => (int) ($a?->extra_time_minutes ?? 0),
                'last_seen' => $seen ? \Illuminate\Support\Carbon::parse($seen)->toIso8601String() : null,
                'flags' => $a?->integrity_flags,
            ];
        });

        return response()->json([
            'exam' => $exam->only(['id', 'title', 'status', 'opens_at', 'closes_at', 'duration_minutes']),
            'counts' => $rows->countBy('state'),
            'students' => $rows->values(),
            'polled_at' => now()->toIso8601String(),
        ]);
    }

    public function extendAttempt(Request $request, int $attemptId): JsonResponse
    {
        $attempt = $this->attempt($attemptId);
        $data = $request->validate(['minutes' => 'required|integer|min:1|max:180', 'reason' => 'required|string|min:3|max:500']);
        $this->mayIntervene($request->user(), $attempt);

        if ($attempt->status !== 'in_progress') {
            return response()->json(['message' => 'Only a running attempt can be given more time.'], 409);
        }

        $this->extend($attempt, $data['minutes'], $data['reason'], $request);

        return response()->json(['message' => "Added {$data['minutes']} minute(s).", 'deadline' => $attempt->fresh()->server_deadline_at?->toIso8601String()]);
    }

    public function extendExam(Request $request, int $id): JsonResponse
    {
        $exam = $this->exam($id);
        $data = $request->validate(['minutes' => 'required|integer|min:1|max:180', 'reason' => 'required|string|min:3|max:500']);
        abort_unless($request->user()->userProfile?->role === 'school_admin', 403, 'Only an admin extends time for the whole exam.');

        $running = CbtAttempt::where('exam_id', $exam->id)->where('status', 'in_progress')->get();

        DB::transaction(function () use ($exam, $running, $data, $request) {
            foreach ($running as $attempt) {
                $this->extend($attempt, $data['minutes'], $data['reason'], $request);
            }

            if ($exam->closes_at) {
                $exam->update(['closes_at' => $exam->closes_at->copy()->addMinutes($data['minutes'])]);
            }
        });

        $this->audit($request, 'cbt.exam_time_extended', CbtExam::class, $exam->id, $data + ['attempts' => $running->count()]);

        return response()->json(['message' => "Added {$data['minutes']} minute(s) for {$running->count()} running attempt(s)."]);
    }

    private function extend(CbtAttempt $attempt, int $minutes, string $reason, Request $request): void
    {
        $attempt->update([
            'server_deadline_at' => ($attempt->server_deadline_at ?? now())->copy()->addMinutes($minutes),
            'extra_time_minutes' => (int) $attempt->extra_time_minutes + $minutes,
        ]);

        $this->exams->logEvent($attempt, 'time_extended', ['minutes' => $minutes, 'reason' => $reason, 'by' => $request->user()->id]);
        $this->audit($request, 'cbt.time_extended', CbtAttempt::class, $attempt->id, ['minutes' => $minutes, 'reason' => $reason]);
    }

    public function forceSubmit(Request $request, int $attemptId): JsonResponse
    {
        $attempt = $this->attempt($attemptId);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);
        $this->mayIntervene($request->user(), $attempt);

        if ($attempt->status !== 'in_progress') {
            return response()->json(['message' => 'Only a running attempt can be submitted.'], 409);
        }

        $this->exams->logEvent($attempt, 'admin_intervention', ['action' => 'force_submit', 'reason' => $data['reason'], 'by' => $request->user()->id]);
        $this->exams->submitAttempt($attempt, 'force_submitted');
        $this->audit($request, 'cbt.force_submitted', CbtAttempt::class, $attempt->id, $data);

        return response()->json(['message' => 'Submitted.']);
    }

    /** Void an attempt so the student can start again. Admin only, with a reason (§7.4). */
    public function reset(Request $request, int $attemptId): JsonResponse
    {
        $attempt = $this->attempt($attemptId);
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
        abort_unless($request->user()->userProfile?->role === 'school_admin', 403, 'Only an admin resets an attempt.');

        $this->exams->logEvent($attempt, 'reset', ['reason' => $data['reason'], 'by' => $request->user()->id, 'previous_status' => $attempt->status]);
        $attempt->update(['status' => 'voided']);
        $this->audit($request, 'cbt.attempt_reset', CbtAttempt::class, $attempt->id, $data);

        return response()->json(['message' => 'Attempt voided. The student can start again; the voided attempt and its answers are kept.']);
    }

    // ==================================================================
    // Analysis (§11)
    // ==================================================================

    public function analysis(Request $request, int $id): JsonResponse
    {
        $exam = $this->exam($id);
        $this->authorize('view', $exam);

        $roster = Student::where('school_id', $exam->school_id)->where('class_id', $exam->class_id)->where('status', 'active')->pluck('id');
        $attempts = CbtAttempt::where('exam_id', $exam->id)->whereNotIn('status', ['voided'])->get();
        $final = $attempts->whereIn('status', ['submitted', 'graded']);
        $pcts = $final->pluck('percentage')->filter(fn ($p) => $p !== null)->map(fn ($p) => (float) $p);

        $grades = $final->groupBy('grade')->map->count();

        $answers = CbtAttemptAnswer::whereIn('attempt_id', $final->pluck('id'))->get()->groupBy('question_id');
        $questions = QuestionBankItem::withoutGlobalScopes()->whereIn('id', $answers->keys())->get()->keyBy('id');

        $items = $answers->map(function ($rows, $questionId) use ($questions) {
            $q = $questions->get($questionId);
            $answered = $rows->filter(fn ($r) => $r->response !== null && $r->response !== []);
            $options = [];

            foreach ($answered as $r) {
                $pick = $r->response['option'] ?? $r->response['selected_option'] ?? $r->response['value'] ?? null;
                if (is_scalar($pick)) {
                    $options[(string) $pick] = ($options[(string) $pick] ?? 0) + 1;
                }
            }

            $correct = $rows->where('is_correct', true)->count();
            $times = $rows->pluck('time_spent_seconds')->filter();

            return [
                'question_id' => (int) $questionId,
                'topic' => $q?->topic,
                'group_id' => $q?->group_id,
                'type' => $q?->question_type,
                'served' => $rows->count(),
                'correct' => $correct,
                'incorrect' => $answered->where('is_correct', false)->count(),
                'skipped' => $rows->count() - $answered->count(),
                'percent_correct' => $rows->count() ? round($correct / $rows->count() * 100, 1) : null,
                'option_counts' => $options,
                'correct_answer' => $q?->correct_answer,
                'average_seconds' => $times->isNotEmpty() ? round($times->avg()) : null,
            ];
        })->sortBy('percent_correct')->values();

        $groups = $items->whereNotNull('group_id')->groupBy('group_id')->map(fn ($g, $gid) => [
            'group_id' => (int) $gid,
            'questions' => $g->count(),
            'percent_correct' => round($g->avg('percent_correct'), 1),
        ])->values();

        $started = $attempts->pluck('student_id')->unique();

        return response()->json([
            'exam' => $exam->only(['id', 'title', 'pass_mark', 'status']),
            'class_report' => [
                'students' => $roster->count(),
                'participants' => $final->count(),
                'non_participants' => $roster->diff($started)->count(),
                'highest' => $pcts->max(),
                'lowest' => $pcts->min(),
                'average' => $pcts->count() ? round($pcts->avg(), 2) : null,
                'pass_rate' => $pcts->count() ? round($pcts->filter(fn ($p) => $p >= (float) $exam->pass_mark)->count() / $pcts->count() * 100, 1) : null,
                'failure_rate' => $pcts->count() ? round($pcts->filter(fn ($p) => $p < (float) $exam->pass_mark)->count() / $pcts->count() * 100, 1) : null,
                'grade_distribution' => $grades,
            ],
            'attendance' => [
                'started' => $started->count(),
                'submitted' => $final->count(),
                'absent' => $roster->diff($started)->count(),
                'absent_students' => Student::whereIn('id', $roster->diff($started))->with('user:id,name')->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->user?->name]),
            ],
            'questions' => $items,
            'groups' => $groups,
            'stale_results' => $this->regrades->stale($exam),
        ]);
    }

    // ==================================================================
    // Versions, revisions and regrades (§3, §10.6)
    // ==================================================================

    public function versions(int $questionId): JsonResponse
    {
        $q = QuestionBankItem::where('school_id', $this->schoolId())->findOrFail($questionId);

        return response()->json(['current' => (int) ($q->version ?? 1), 'data' => CbtQuestionVersion::where('question_id', $q->id)->orderBy('version')->get()]);
    }

    public function revisions(Request $request, int $attemptId): JsonResponse
    {
        $attempt = $this->attempt($attemptId);
        $this->authorize('view', CbtExam::withoutGlobalScopes()->findOrFail($attempt->exam_id));

        return response()->json(['data' => CbtResultRevision::where('attempt_id', $attempt->id)->orderBy('revision')->get()]);
    }

    public function regradePreview(Request $request, int $id): JsonResponse
    {
        [$exam, $question, $data] = $this->regradeInput($request, $id);

        return $this->attempt_(fn () => $this->regrades->preview($exam, $question, $data['action'], $data['params'] ?? [], $data['scope'] ?? 'exam'));
    }

    public function regrade(Request $request, int $id): JsonResponse
    {
        [$exam, $question, $data] = $this->regradeInput($request, $id);
        $request->validate(['reason' => 'required|string|min:5|max:1000']);

        return $this->attempt_(fn () => ['job' => $this->regrades->apply($exam, $question, $data['action'], $data['params'] ?? [], $data['scope'] ?? 'exam', $request->input('reason'), $request->user())]);
    }

    public function regradeJobs(int $id): JsonResponse
    {
        $exam = $this->exam($id);

        return response()->json(['data' => CbtRegradeJob::where('exam_id', $exam->id)->orderByDesc('id')->get()]);
    }

    public function rollback(Request $request, int $jobId): JsonResponse
    {
        $job = CbtRegradeJob::where('school_id', $this->schoolId())->findOrFail($jobId);
        $request->validate(['reason' => 'required|string|min:5|max:1000']);

        return $this->attempt_(fn () => ['job' => $this->regrades->rollback($job, $request->user(), $request->input('reason'))]);
    }

    private function regradeInput(Request $request, int $id): array
    {
        $exam = $this->exam($id);
        $this->authorize('view', $exam);

        $data = $request->validate([
            'question_id' => ['required', Rule::exists('cbt_exam_questions', 'question_id')->where('exam_id', $exam->id)],
            'action' => ['required', Rule::in(array_keys(CbtRegradeJob::ACTIONS))],
            'params' => 'nullable|array',
            'scope' => 'nullable|in:exam,all_exams',
        ]);

        $question = QuestionBankItem::where('school_id', $exam->school_id)->findOrFail($data['question_id']);

        return [$exam, $question, $data];
    }

    // ==================================================================

    private function exam(int $id): CbtExam
    {
        return CbtExam::where('school_id', $this->schoolId())->findOrFail($id);
    }

    private function attempt(int $id): CbtAttempt
    {
        return CbtAttempt::where('school_id', $this->schoolId())->findOrFail($id);
    }

    /** Admins and the exam's author intervene; invigilators only where an admin granted it (via authorship here). */
    private function mayIntervene(User $user, CbtAttempt $attempt): void
    {
        $exam = CbtExam::withoutGlobalScopes()->findOrFail($attempt->exam_id);
        abort_unless($user->userProfile?->role === 'school_admin' || (int) $exam->created_by === $user->id, 403, 'Only an admin or the exam\'s author can intervene.');
    }

    private function exams_log(CbtExam $exam, string $action, User $user, ?string $note = null): void
    {
        AuditLog::create(['school_id' => $exam->school_id, 'user_id' => $user->id, 'action' => 'cbt.exam_'.$action, 'auditable_type' => CbtExam::class, 'auditable_id' => $exam->id, 'new_values' => ['note' => $note]]);
    }

    private function audit(Request $request, string $action, string $type, int $id, array $values): void
    {
        AuditLog::create(['school_id' => $this->schoolId(), 'user_id' => $request->user()->id, 'action' => $action, 'auditable_type' => $type, 'auditable_id' => $id, 'new_values' => $values, 'ip_address' => $request->ip()]);
    }

    private function attempt_(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (AuthorisationFailure $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
