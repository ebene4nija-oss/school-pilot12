<?php

namespace App\Services\Cbt;

use App\Exceptions\DomainRefusal;
use App\Models\AuditLog;
use App\Models\CbtAttempt;
use App\Models\CbtAttemptAnswer;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\CbtRegradeJob;
use App\Models\QuestionBankItem;
use App\Models\Student;
use App\Models\User;
use App\Services\CbtExamService;
use App\Services\CbtGradingService;
use App\Services\Results\AuthorisationFailure;
use App\Support\InAppNotifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Regrading (CBT instructions §10.6).
 *
 * Editing never changes a result by itself. A regrade is a deliberate act:
 * previewed (who goes up, who goes down, grade and pass/fail changes, class
 * average before and after), applied with a reason, recorded as a new result
 * revision that keeps the old one, repeatable without effect, and reversible.
 *
 * Teachers regrade their own exams while results are not yet out; after an
 * exam is closed, only an admin can.
 */
class CbtRegradeService
{
    public function __construct(
        private CbtGradingService $grader,
        private QuestionVersioner $versions,
        private CbtResultLedger $ledger,
        private CbtExamService $exams,
    ) {
    }

    /**
     * @param  array<string, mixed>  $params  correct_answer | correct_answers
     */
    public function preview(CbtExam $exam, QuestionBankItem $question, string $action, array $params, string $scope = 'exam'): array
    {
        $rows = $this->compute($exam, $question, $action, $params, $scope);
        $byExam = $rows->groupBy('exam_id');

        return [
            'action' => $action,
            'scope' => $scope,
            'attempts' => $rows->count(),
            'up' => $rows->filter(fn ($r) => $r['new_percentage'] > $r['old_percentage'])->count(),
            'down' => $rows->filter(fn ($r) => $r['new_percentage'] < $r['old_percentage'])->count(),
            'unchanged' => $rows->filter(fn ($r) => $r['new_percentage'] == $r['old_percentage'])->count(),
            'grade_changes' => $rows->filter(fn ($r) => $r['old_grade'] !== $r['new_grade'])->count(),
            'pass_fail_changes' => $rows->filter(fn ($r) => $r['old_passed'] !== $r['new_passed'])->count(),
            'exams' => $byExam->map(fn ($g, $examId) => [
                'exam_id' => (int) $examId,
                'average_before' => round($g->avg('old_percentage'), 2),
                'average_after' => round($g->avg('new_percentage'), 2),
            ])->values(),
            'students' => $rows->map(fn ($r) => array_intersect_key($r, array_flip(['attempt_id', 'student_name', 'old_percentage', 'new_percentage', 'old_grade', 'new_grade', 'old_passed', 'new_passed'])))->values(),
        ];
    }

    public function apply(CbtExam $exam, QuestionBankItem $question, string $action, array $params, string $scope, string $reason, User $by): CbtRegradeJob
    {
        $this->authorise($exam, $by);

        $hash = hash('sha256', json_encode([$exam->id, $question->id, $action, $params, $scope]));

        // The same regrade twice gives the same result: return the first (rule 8).
        $existing = CbtRegradeJob::withoutGlobalScopes()->where('exam_id', $exam->id)->where('params_hash', $hash)->where('status', 'applied')->first();
        if ($existing) {
            return $existing;
        }

        $rows = $this->compute($exam, $question, $action, $params, $scope);

        return DB::transaction(function () use ($exam, $question, $action, $params, $scope, $reason, $by, $hash, $rows) {
            $before = [
                'answers' => CbtAttemptAnswer::whereIn('attempt_id', $rows->pluck('attempt_id'))->where('question_id', $question->id)
                    ->get(['attempt_id', 'question_id', 'awarded_marks', 'is_correct'])->toArray(),
                'attempts' => CbtAttempt::withoutGlobalScopes()->whereIn('id', $rows->pluck('attempt_id'))
                    ->get(['id', 'raw_score', 'max_score', 'percentage', 'grade', 'status'])->toArray(),
            ];

            $job = CbtRegradeJob::withoutGlobalScopes()->create([
                'school_id' => $exam->school_id,
                'exam_id' => $exam->id,
                'question_id' => $question->id,
                'action' => $action,
                'params' => $params,
                'scope' => $scope,
                'reason' => $reason,
                'params_hash' => $hash,
                'status' => 'applied',
                'before' => json_encode($before),
                'requested_by' => $by->id,
                'applied_at' => now(),
            ]);

            $changedStudents = [];

            foreach ($rows as $r) {
                CbtAttemptAnswer::where('attempt_id', $r['attempt_id'])->where('question_id', $question->id)->update([
                    'awarded_marks' => $r['new_awarded'],
                    'is_correct' => $r['new_correct'],
                    'graded_by' => 'regrade',
                ]);

                $attempt = CbtAttempt::withoutGlobalScopes()->find($r['attempt_id']);
                $attempt->update([
                    'raw_score' => $r['new_raw'],
                    'max_score' => $r['new_max'],
                    'percentage' => $r['new_percentage'],
                    'grade' => $r['new_grade'],
                ]);

                if ($r['new_percentage'] != $r['old_percentage'] || $r['new_max'] != $r['old_max']) {
                    $this->ledger->record($attempt->fresh(), "Regrade #{$job->id}: {$reason}", $by, $job->id, ['regrade' => ['action' => $action, 'question_id' => $question->id]]);
                    $this->exams->logEvent($attempt, 'regraded', ['job' => $job->id, 'from' => $r['old_percentage'], 'to' => $r['new_percentage']]);
                }

                if ($r['new_percentage'] != $r['old_percentage']) {
                    $changedStudents[] = $r['student_id'];
                }
            }

            // A corrected key is the question's answer from now on (a new version).
            if ($action === 'change_key') {
                $this->versions->update($question->fresh(), ['correct_answer' => $params['correct_answer']], $by);
            }

            $job->update(['summary' => [
                'attempts' => $rows->count(),
                'changed' => count($changedStudents),
                'up' => $rows->filter(fn ($r) => $r['new_percentage'] > $r['old_percentage'])->count(),
                'down' => $rows->filter(fn ($r) => $r['new_percentage'] < $r['old_percentage'])->count(),
            ]]);

            // Only the students whose scores changed are told (§10.6).
            $userIds = Student::withoutGlobalScopes()->whereIn('id', $changedStudents)->pluck('user_id')->filter()->all();
            InAppNotifier::send($exam->school_id, $userIds, 'cbt_regrade', 'Result corrected', "Your result for \"{$exam->title}\" was corrected. Open your results to see it.");

            AuditLog::create([
                'school_id' => $exam->school_id, 'user_id' => $by->id, 'action' => 'cbt.regraded',
                'auditable_type' => CbtRegradeJob::class, 'auditable_id' => $job->id,
                'new_values' => ['action' => $action, 'question_id' => $question->id, 'scope' => $scope, 'reason' => $reason, 'changed' => count($changedStudents)],
            ]);

            return $job->fresh();
        });
    }

    /** Restore exactly what the regrade replaced, as a further revision. */
    public function rollback(CbtRegradeJob $job, User $by, string $reason): CbtRegradeJob
    {
        $exam = CbtExam::withoutGlobalScopes()->findOrFail($job->exam_id);
        $this->authorise($exam, $by);

        if ($job->status !== 'applied') {
            throw new DomainRefusal('This regrade has already been rolled back.');
        }

        $before = json_decode((string) $job->before, true) ?? ['answers' => [], 'attempts' => []];
        $attemptIds = array_column($before['attempts'], 'id');

        $later = CbtRegradeJob::withoutGlobalScopes()->where('status', 'applied')->where('id', '>', $job->id)
            ->get()->contains(fn ($j) => array_intersect(array_column(json_decode((string) $j->before, true)['attempts'] ?? [], 'id'), $attemptIds) !== []);

        if ($later) {
            throw new DomainRefusal('A later regrade changed the same results. Roll that one back first.');
        }

        return DB::transaction(function () use ($job, $by, $reason, $before) {
            foreach ($before['answers'] as $a) {
                CbtAttemptAnswer::where('attempt_id', $a['attempt_id'])->where('question_id', $a['question_id'])
                    ->update(['awarded_marks' => $a['awarded_marks'], 'is_correct' => $a['is_correct'], 'graded_by' => 'auto']);
            }

            foreach ($before['attempts'] as $a) {
                $attempt = CbtAttempt::withoutGlobalScopes()->find($a['id']);
                $attempt->update(array_intersect_key($a, array_flip(['raw_score', 'max_score', 'percentage', 'grade'])));
                $this->ledger->record($attempt->fresh(), "Rollback of regrade #{$job->id}: {$reason}", $by, $job->id);
            }

            $job->update(['status' => 'rolled_back', 'rolled_back_by' => $by->id, 'rolled_back_at' => now()]);

            AuditLog::create([
                'school_id' => $job->school_id, 'user_id' => $by->id, 'action' => 'cbt.regrade_rolled_back',
                'auditable_type' => CbtRegradeJob::class, 'auditable_id' => $job->id, 'new_values' => ['reason' => $reason],
            ]);

            return $job->fresh();
        });
    }

    /** Results still marked on a version older than the question's current one. */
    public function stale(CbtExam $exam): Collection
    {
        return DB::table('cbt_attempt_answers as aa')
            ->join('cbt_attempts as a', 'a.id', '=', 'aa.attempt_id')
            ->join('question_bank as q', 'q.id', '=', 'aa.question_id')
            ->join('cbt_question_versions as v', function ($j) {
                $j->on('v.question_id', '=', 'q.id')->on('v.version', '=', 'q.version');
            })
            ->where('a.exam_id', $exam->id)
            ->whereColumn('aa.question_version', '<', 'q.version')
            ->where('v.affects_scoring', true)
            ->selectRaw('aa.question_id, COUNT(*) as attempts, MAX(q.version) as current_version')
            ->groupBy('aa.question_id')
            ->get();
    }

    // ------------------------------------------------------------------

    private function compute(CbtExam $exam, QuestionBankItem $question, string $action, array $params, string $scope): Collection
    {
        if (! isset(CbtRegradeJob::ACTIONS[$action])) {
            throw new DomainRefusal('Unknown regrade action.');
        }

        if (in_array($action, ['change_key', 'accept_answers'], true) && ! $question->isAutoGradable()) {
            throw new DomainRefusal('Only automatically marked questions can have their key changed. Re-mark theory answers in the marking queue.');
        }

        $examIds = $scope === 'all_exams'
            ? CbtExamQuestion::where('question_id', $question->id)->pluck('exam_id')->all()
            : [$exam->id];

        $exams = CbtExam::withoutGlobalScopes()->where('school_id', $exam->school_id)->whereIn('id', $examIds)->get()->keyBy('id');
        $links = CbtExamQuestion::whereIn('exam_id', $exams->keys())->where('question_id', $question->id)->get()->keyBy('exam_id');

        $attempts = CbtAttempt::withoutGlobalScopes()->whereIn('exam_id', $exams->keys())->whereIn('status', ['submitted', 'graded'])
            ->with('student.user:id,name')->get();

        $answers = CbtAttemptAnswer::whereIn('attempt_id', $attempts->pluck('id'))->where('question_id', $question->id)->get()->keyBy('attempt_id');

        return $attempts->filter(fn ($a) => $answers->has($a->id))->map(function (CbtAttempt $a) use ($exams, $links, $answers, $question, $action, $params) {
            $exam = $exams[$a->exam_id];
            $answer = $answers[$a->id];
            $link = $links->get($a->exam_id);
            $marks = $link ? $link->effectiveMarks($question) : (float) $question->marks;
            $negative = $exam->negative_marking ? ($link ? $link->effectiveNegativeMarks($question) : (float) $question->negative_marks) : 0.0;
            $served = $this->versions->at($question, $answer->question_version);

            $old = (float) ($answer->awarded_marks ?? 0);
            $max = (float) $a->max_score;
            $raw = (float) $a->raw_score;

            [$new, $correct, $newMax] = match ($action) {
                'change_key' => $this->regrade($served, $answer->response, $marks, $negative, [$params['correct_answer'] ?? null]) + [2 => $max],
                'accept_answers' => $this->regrade($served, $answer->response, $marks, $negative, (array) ($params['correct_answers'] ?? [])) + [2 => $max],
                'credit_all' => [$marks, true, $max],
                'drop' => [0.0, null, max(0.0, $max - $marks)],
            };

            $newRaw = max(0.0, round($raw - $old + $new, 2));
            $newPct = $newMax > 0 ? round($newRaw / $newMax * 100, 2) : 0.0;
            $oldPct = (float) $a->percentage;
            $passMark = (float) $exam->pass_mark;

            return [
                'attempt_id' => $a->id,
                'exam_id' => $a->exam_id,
                'student_id' => $a->student_id,
                'student_name' => $a->student?->user?->name,
                'old_awarded' => $old,
                'new_awarded' => $new,
                'new_correct' => $correct,
                'old_raw' => $raw,
                'new_raw' => $newRaw,
                'old_max' => $max,
                'new_max' => round($newMax, 2),
                'old_percentage' => $oldPct,
                'new_percentage' => $newPct,
                'old_grade' => $a->grade,
                'new_grade' => $this->exams->waecGrade($newPct),
                'old_passed' => $oldPct >= $passMark,
                'new_passed' => $newPct >= $passMark,
            ];
        })->values();
    }

    /** Marks under the best of several accepted keys. @return array{0: float, 1: bool} */
    private function regrade(QuestionBankItem $served, ?array $response, float $marks, float $negative, array $keys): array
    {
        $best = [0.0 - $negative, false];
        $tried = false;

        foreach ($keys as $key) {
            if ($key === null) {
                continue;
            }

            $q = $served->replicate();
            $q->id = $served->id;
            $q->correct_answer = is_array($key) ? implode(',', $key) : (string) $key;

            $r = $this->grader->grade($q, $response, $marks, $negative);
            $tried = true;

            if ($r['awarded_marks'] > $best[0]) {
                $best = [(float) $r['awarded_marks'], (bool) $r['is_correct']];
            }
        }

        if (! $tried) {
            throw new DomainRefusal('Give the corrected answer.');
        }

        return $best;
    }

    private function authorise(CbtExam $exam, User $by): void
    {
        $role = $by->userProfile?->role;

        if (in_array($role, ['school_admin', 'super_admin'], true)) {
            return;
        }

        if ($exam->status === 'closed') {
            throw new AuthorisationFailure('Results for this exam are out. Only an admin can regrade it now.');
        }

        if ((int) $exam->created_by !== $by->id) {
            throw new AuthorisationFailure('You can regrade only exams you set.');
        }
    }
}
