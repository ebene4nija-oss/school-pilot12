<?php

namespace App\Services\Cbt;

use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtResultRevision;
use App\Models\QuestionBankItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CBT results as a series of revisions (CBT instructions §3 rule 4).
 *
 * Every time a result's figures are set — at submission, after manual
 * marking, after a regrade or a rollback — a revision records the figures
 * and the rules used to reach them. Students and parents see the latest;
 * staff see the history.
 */
class CbtResultLedger
{
    public function record(CbtAttempt $attempt, string $reason, ?User $by = null, ?int $regradeJobId = null, array $extraRules = []): CbtResultRevision
    {
        $exam = CbtExam::withoutGlobalScopes()->find($attempt->exam_id);

        return DB::transaction(function () use ($attempt, $reason, $by, $regradeJobId, $extraRules, $exam) {
            $next = (int) CbtResultRevision::withoutGlobalScopes()->where('attempt_id', $attempt->id)->lockForUpdate()->max('revision') + 1;

            return CbtResultRevision::withoutGlobalScopes()->create([
                'school_id' => $attempt->school_id,
                'attempt_id' => $attempt->id,
                'revision' => $next,
                'raw_score' => $attempt->raw_score,
                'max_score' => $attempt->max_score,
                'percentage' => $attempt->percentage,
                'grade' => $attempt->grade,
                'rules' => [
                    'pass_mark' => (float) ($exam?->pass_mark ?? 40),
                    'negative_marking' => (bool) ($exam?->negative_marking ?? false),
                    'grading' => 'waec_nine_point',
                ] + $extraRules,
                'reason' => $reason,
                'regrade_job_id' => $regradeJobId,
                'created_by' => $by?->id,
            ]);
        });
    }

    /** Pin each answer to the question version it was marked against. */
    public function pinVersions(CbtAttempt $attempt): void
    {
        $versions = QuestionBankItem::withoutGlobalScopes()->whereIn('id', $attempt->question_order ?? [])->pluck('version', 'id');

        foreach ($versions as $questionId => $version) {
            DB::table('cbt_attempt_answers')
                ->where('attempt_id', $attempt->id)
                ->where('question_id', $questionId)
                ->whereNull('question_version')
                ->update(['question_version' => $version ?? 1]);
        }
    }
}
