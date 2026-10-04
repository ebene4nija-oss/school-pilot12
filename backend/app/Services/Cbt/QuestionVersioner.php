<?php

namespace App\Services\Cbt;

use App\Models\CbtQuestionVersion;
use App\Models\QuestionBankItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Question versions (CBT instructions §3 rule 2, §4.6).
 *
 * Editing a question nobody has answered just edits it. Editing one that has
 * been answered keeps the answered version, bumps the version, and — if the
 * edit touches what is correct — leaves the old results exactly as they were
 * until someone deliberately regrades (§10.6). Those results show as "graded
 * on an older version" meanwhile.
 */
class QuestionVersioner
{
    /**
     * Apply an edit, versioning it when the question has been answered.
     *
     * @param  array<string, mixed>  $changes
     * @return array{versioned: bool, affects_scoring: bool, version: int}
     */
    public function update(QuestionBankItem $question, array $changes, ?User $by): array
    {
        $answered = DB::table('cbt_attempt_answers')->where('question_id', $question->id)->exists();

        $touched = array_values(array_filter(
            CbtQuestionVersion::FIELDS,
            fn ($f) => array_key_exists($f, $changes) && json_encode($changes[$f]) !== json_encode($question->getAttribute($f))
        ));

        if (! $answered || $touched === []) {
            $question->update($changes);

            return ['versioned' => false, 'affects_scoring' => false, 'version' => (int) ($question->version ?? 1)];
        }

        $affectsScoring = array_intersect($touched, CbtQuestionVersion::SCORING_FIELDS) !== [];

        return DB::transaction(function () use ($question, $changes, $by, $touched, $affectsScoring) {
            $current = (int) ($question->version ?? 1);

            // The answered version, kept as it was.
            CbtQuestionVersion::withoutGlobalScopes()->firstOrCreate(
                ['question_id' => $question->id, 'version' => $current],
                ['school_id' => $question->school_id, 'payload' => $this->payload($question), 'change_summary' => 'As answered', 'created_by' => $by?->id]
            );

            $question->update($changes + ['version' => $current + 1]);

            CbtQuestionVersion::withoutGlobalScopes()->create([
                'school_id' => $question->school_id,
                'question_id' => $question->id,
                'version' => $current + 1,
                'payload' => $this->payload($question->fresh()),
                'change_summary' => 'Changed: '.implode(', ', $touched),
                'affects_scoring' => $affectsScoring,
                'created_by' => $by?->id,
            ]);

            return ['versioned' => true, 'affects_scoring' => $affectsScoring, 'version' => $current + 1];
        });
    }

    /** The question as it was at a version, or as it is now. */
    public function at(QuestionBankItem $question, ?int $version): QuestionBankItem
    {
        if ($version === null || $version === (int) ($question->version ?? 1)) {
            return $question;
        }

        $row = CbtQuestionVersion::withoutGlobalScopes()->where('question_id', $question->id)->where('version', $version)->first();

        if (! $row) {
            return $question;
        }

        $clone = $question->replicate();
        $clone->id = $question->id;
        $clone->forceFill(array_intersect_key($row->payload, array_flip(CbtQuestionVersion::FIELDS)));

        return $clone;
    }

    private function payload(QuestionBankItem $q): array
    {
        return collect(CbtQuestionVersion::FIELDS)->mapWithKeys(fn ($f) => [$f => $q->getAttribute($f)])->all();
    }
}
