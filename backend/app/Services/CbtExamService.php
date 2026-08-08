<?php

namespace App\Services;

use App\Models\CbtAttempt;
use App\Models\CbtAttemptAnswer;
use App\Models\CbtAttemptEvent;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\QuestionBankItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Attempt lifecycle: starting a paper, serving it, saving answers, submitting,
 * and grading.
 *
 * Three properties this service is built around.
 *
 * **The server owns the clock.** `server_deadline_at` is set once, at start,
 * from the server's own time. Nothing the client sends can extend it. A
 * candidate who closes the laptop for ten minutes loses ten minutes, exactly
 * as they would in a hall.
 *
 * **Shuffling is deterministic, not random.** Each attempt gets a seed;
 * question and option order are derived from it. A candidate whose power cuts
 * mid-paper resumes to the same paper they were sitting, and an invigilator
 * reviewing a dispute can reproduce exactly what that candidate saw. Storing
 * a seed also means we do not snapshot 60 questions per attempt.
 *
 * **Answers never travel with their marking scheme.** The payload served to a
 * candidate has `correct_answer`, `explanation` and the answer half of
 * `answer_schema` removed. Anything else is a scoreboard published to
 * DevTools.
 */
class CbtExamService
{
    public function __construct(
        private CbtGradingService $grader,
        private CbtMediaService $media
    ) {
    }

    // ------------------------------------------------------------------
    // Starting and resuming
    // ------------------------------------------------------------------

    /**
     * Start a new attempt, or hand back the one already in progress.
     *
     * @throws \RuntimeException when the candidate may not sit this paper
     */
    public function startAttempt(CbtExam $exam, int $studentId, array $context = []): CbtAttempt
    {
        $now = now();

        if (!$exam->isOpenAt($now)) {
            throw new \RuntimeException($exam->unavailableReason($now) ?? 'This exam is not available.');
        }

        // Serialise per student so a double-tapped "Start" button cannot open
        // two attempts and burn an allowance.
        return DB::transaction(function () use ($exam, $studentId, $context, $now) {
            $existing = CbtAttempt::withoutGlobalScopes()
                ->where('exam_id', $exam->id)
                ->where('student_id', $studentId)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->get();

            $open = $existing->firstWhere('status', 'in_progress');
            if ($open) {
                // Resuming: if the deadline passed while they were away, close
                // it out rather than letting them keep typing.
                if ($open->hasExpired($now)) {
                    $this->submitAttempt($open, 'auto_submit');

                    throw new \RuntimeException('Your time for this exam has already elapsed and the attempt was submitted.');
                }

                $this->logEvent($open, 'resumed', ['at' => $now->toIso8601String()]);

                return $open;
            }

            $used = $existing->whereIn('status', ['submitted', 'graded', 'expired'])->count();
            if ($used >= $exam->max_attempts) {
                throw new \RuntimeException(sprintf(
                    'You have used all %d permitted attempt(s) for this exam.',
                    $exam->max_attempts
                ));
            }

            $questionIds = $this->drawQuestions($exam);

            if ($questionIds === []) {
                throw new \RuntimeException('This exam has no questions attached yet.');
            }

            $extraTime = (int) ($context['extra_time_minutes'] ?? 0);
            $deadline = $now->copy()->addMinutes($exam->duration_minutes + $extraTime);

            // Never let an attempt run past the exam window's own close time.
            if ($exam->closes_at && $deadline->greaterThan($exam->closes_at)) {
                $deadline = $exam->closes_at->copy();
            }

            $attempt = CbtAttempt::create([
                'school_id' => $exam->school_id,
                'exam_id' => $exam->id,
                'student_id' => $studentId,
                'attempt_number' => $existing->max('attempt_number') + 1,
                'seed' => random_int(1, PHP_INT_MAX - 1),
                'question_order' => $questionIds,
                'status' => 'in_progress',
                'started_at' => $now,
                'server_deadline_at' => $deadline,
                'extra_time_minutes' => $extraTime,
                'device_fingerprint' => $context['device_fingerprint'] ?? null,
                'ip_address' => $context['ip_address'] ?? null,
            ]);

            return $attempt;
        });
    }

    /**
     * Which questions this attempt sits. With `questions_per_attempt` set, a
     * different random subset is drawn per attempt — the standard defence
     * against candidates comparing answers across a lab.
     *
     * @return array<int,int>
     */
    private function drawQuestions(CbtExam $exam): array
    {
        $links = CbtExamQuestion::where('exam_id', $exam->id)
            ->orderBy('order_index')
            ->get();

        $ids = $links->pluck('question_id')->map(fn ($id) => (int) $id)->all();

        if ($exam->questions_per_attempt && $exam->questions_per_attempt < count($ids)) {
            shuffle($ids);
            $ids = array_slice($ids, 0, $exam->questions_per_attempt);
        }

        return array_values($ids);
    }

    // ------------------------------------------------------------------
    // Serving the paper
    // ------------------------------------------------------------------

    /**
     * The candidate-facing paper: questions in this attempt's order, options
     * shuffled by this attempt's seed, media hydrated, answers removed.
     */
    public function buildCandidatePaper(CbtAttempt $attempt, CbtExam $exam): array
    {
        $questionIds = $attempt->question_order ?? [];
        $questions = QuestionBankItem::withoutGlobalScopes()
            ->whereIn('id', $questionIds)
            ->get()
            ->keyBy('id');

        $ordered = collect($questionIds)
            ->map(fn ($id) => $questions->get($id))
            ->filter()
            ->values();

        if ($exam->shuffle_questions) {
            $ordered = $this->seededShuffle($ordered, $attempt->seed);
        }

        $links = CbtExamQuestion::where('exam_id', $exam->id)
            ->whereIn('question_id', $questionIds)
            ->get()
            ->keyBy('question_id');

        $assets = $this->media->hydrateForQuestions($ordered);
        $saved = CbtAttemptAnswer::where('attempt_id', $attempt->id)->get()->keyBy('question_id');

        $payload = [];
        $number = 1;

        foreach ($ordered as $question) {
            $link = $links->get($question->id);
            $payload[] = $this->presentQuestion(
                $question,
                $attempt,
                $exam,
                $link,
                $assets,
                $number++,
                $saved->get($question->id)
            );
        }

        return $payload;
    }

    private function presentQuestion(
        QuestionBankItem $question,
        CbtAttempt $attempt,
        CbtExam $exam,
        ?CbtExamQuestion $link,
        array $assets,
        int $number,
        ?CbtAttemptAnswer $saved
    ): array {
        $options = $this->presentOptions($question, $attempt, $exam, $assets);

        return [
            'number' => $number,
            'question_id' => $question->id,
            'question_type' => $question->question_type,
            'content_format' => $question->content_format ?? 'plain',
            'topic' => $question->topic,
            'difficulty' => $question->difficulty,
            'section' => $link?->section,
            'marks' => $link ? $link->effectiveMarks($question) : (float) $question->marks,
            'negative_marks' => $link ? $link->effectiveNegativeMarks($question) : (float) $question->negative_marks,
            'question' => $question->question,
            'media' => $this->presentMedia($question, $assets),
            'options' => $options,
            // Geometry only — the labels that make it gradeable stay server-side.
            'interaction' => $this->publicAnswerSchema($question),
            'saved_response' => $saved?->response,
        ];
    }

    /**
     * Options with any per-option image attached, shuffled deterministically.
     * Option keys travel with the option so a shuffled paper still grades
     * against the original key.
     */
    private function presentOptions(QuestionBankItem $question, CbtAttempt $attempt, CbtExam $exam, array $assets): array
    {
        $raw = $question->options;
        if (empty($raw) || !is_array($raw)) {
            return [];
        }

        $mediaByRole = collect($question->media ?? [])->groupBy('role');

        $options = collect($raw)->map(function ($value, $key) use ($mediaByRole, $assets) {
            // Options are either { "A": "text" } or [{ key, text }].
            if (is_array($value)) {
                $optionKey = (string) ($value['key'] ?? $key);
                $text = $value['text'] ?? $value['label'] ?? '';
            } else {
                $optionKey = (string) $key;
                $text = (string) $value;
            }

            $image = null;
            $refs = $mediaByRole->get('option:' . $optionKey);
            if ($refs && $refs->isNotEmpty()) {
                $image = $assets[$refs->first()['asset_id']] ?? null;
            }

            return [
                'key' => $optionKey,
                'text' => $text,
                'image' => $image,
            ];
        })->values();

        if ($exam->shuffle_options && $this->isShuffleable($question)) {
            // Seed per question so shuffling question 7's options does not
            // depend on how many questions came before it.
            $options = $this->seededShuffle($options, $attempt->seed + $question->id);
        }

        return $options->all();
    }

    /**
     * Ordering questions must not have their options shuffled — the order IS
     * the answer, and shuffling the prompt list is fine but is handled by the
     * client from `interaction.items`.
     */
    private function isShuffleable(QuestionBankItem $question): bool
    {
        return !in_array($question->question_type, ['ordering', 'matching'], true);
    }

    /** Stem, diagram and explanation images, grouped by role. */
    private function presentMedia(QuestionBankItem $question, array $assets, bool $includeExplanation = false): array
    {
        $out = [];

        foreach ($question->media ?? [] as $reference) {
            $role = $reference['role'] ?? 'stem';

            if (str_starts_with($role, 'option:')) {
                continue; // already attached to its option
            }

            if ($role === 'explanation' && !$includeExplanation) {
                continue; // withheld until the attempt is over
            }

            $asset = $assets[$reference['asset_id']] ?? null;
            if ($asset) {
                $out[] = ['role' => $role, 'position' => $reference['position'] ?? 0] + $asset;
            }
        }

        usort($out, fn ($a, $b) => $a['position'] <=> $b['position']);

        return $out;
    }

    /**
     * The half of answer_schema a candidate is allowed to see: drop zone
     * geometry, the label pool to drag from, matching columns, numeric unit
     * hints. Never the correct label for a zone, the correct pairs, or the
     * accepted spellings.
     */
    private function publicAnswerSchema(QuestionBankItem $question): array
    {
        $schema = $question->answer_schema ?? [];

        return match ($question->question_type) {
            'diagram_label' => [
                'zones' => collect($schema['zones'] ?? [])->map(fn ($zone) => [
                    'id' => $zone['id'] ?? null,
                    'x' => $zone['x'] ?? null,
                    'y' => $zone['y'] ?? null,
                    'w' => $zone['w'] ?? null,
                    'h' => $zone['h'] ?? null,
                ])->values()->all(),
                // Every label plus any distractors, shuffled by the client.
                'label_pool' => array_values($schema['label_pool'] ?? []),
            ],
            'hotspot' => [
                'coordinate_space' => 'normalised_0_1',
            ],
            'matching' => [
                'left' => array_values($schema['left'] ?? array_keys($schema['pairs'] ?? [])),
                'right' => array_values($schema['right'] ?? array_values($schema['pairs'] ?? [])),
            ],
            'ordering' => [
                'items' => array_values($schema['items'] ?? []),
            ],
            'numeric' => array_filter([
                'unit' => $schema['unit'] ?? null,
                'decimal_places_hint' => $schema['decimal_places_hint'] ?? null,
            ], fn ($v) => $v !== null),
            'theory' => [
                'allow_image_answer' => (bool) ($schema['allow_image_answer'] ?? true),
                'max_words' => $schema['max_words'] ?? null,
            ],
            default => [],
        };
    }

    // ------------------------------------------------------------------
    // Answering
    // ------------------------------------------------------------------

    /**
     * Record answers against an open attempt.
     *
     * Shared by the live client and the offline sync endpoint, which is the
     * point: one code path means an offline batch cannot take a shortcut the
     * online path does not allow.
     *
     * @param array<int,array> $answers each: question_id, response, optional
     *                                  client_timestamp / client_sequence
     * @return array{saved: int, ignored: int, rejected: array<int,string>}
     */
    public function recordAnswers(CbtAttempt $attempt, array $answers, bool $fromOfflineClient = false): array
    {
        if ($attempt->isFinalised()) {
            return [
                'saved' => 0,
                'ignored' => count($answers),
                'rejected' => ['Attempt is already finalised; no further answers accepted.'],
            ];
        }

        $permitted = collect($attempt->question_order ?? [])->flip();
        $saved = 0;
        $ignored = 0;
        $rejected = [];

        DB::transaction(function () use ($attempt, $answers, $permitted, $fromOfflineClient, &$saved, &$ignored, &$rejected) {
            foreach ($answers as $answer) {
                $questionId = (int) ($answer['question_id'] ?? 0);

                // A question that was never served to this attempt cannot be
                // answered by it — this is the check that stops a candidate
                // POSTing answers to the whole bank.
                if (!$permitted->has($questionId)) {
                    $rejected[] = "Question {$questionId} is not part of this attempt.";
                    continue;
                }

                $response = $answer['response'] ?? null;
                if ($response !== null && !is_array($response)) {
                    $response = ['value' => $response];
                }

                $clientTimestamp = $this->parseTimestamp($answer['client_timestamp'] ?? null);
                $clientSequence = (int) ($answer['client_sequence'] ?? 0);

                $existing = CbtAttemptAnswer::where('attempt_id', $attempt->id)
                    ->where('question_id', $questionId)
                    ->lockForUpdate()
                    ->first();

                if ($existing && !$this->supersedes($existing, $clientTimestamp, $clientSequence)) {
                    $ignored++;
                    continue;
                }

                CbtAttemptAnswer::updateOrCreate(
                    ['attempt_id' => $attempt->id, 'question_id' => $questionId],
                    [
                        'response' => $response,
                        'client_timestamp' => $clientTimestamp,
                        'client_sequence' => $clientSequence,
                        'time_spent_seconds' => isset($answer['time_spent_seconds'])
                            ? (int) $answer['time_spent_seconds']
                            : ($existing->time_spent_seconds ?? null),
                        'flagged_for_review' => (bool) ($answer['flagged_for_review'] ?? false),
                    ]
                );

                $saved++;
            }
        });

        if ($fromOfflineClient) {
            $this->logEvent($attempt, 'offline_sync', [
                'saved' => $saved,
                'ignored' => $ignored,
                'rejected' => count($rejected),
            ]);
        }

        return ['saved' => $saved, 'ignored' => $ignored, 'rejected' => $rejected];
    }

    /**
     * Should an incoming answer replace the stored one?
     *
     * The old implementation compared date strings with `>=`, which silently
     * mis-ordered anything not in an identical format and treated a re-send of
     * the same answer as newer. Ordering is now: explicit client sequence
     * first (monotonic per device, so it survives a clock change), then
     * timestamp, then "an answer with no ordering information never overwrites
     * one that has some".
     */
    private function supersedes(CbtAttemptAnswer $existing, ?\DateTimeInterface $timestamp, int $sequence): bool
    {
        if ($sequence > 0 && $existing->client_sequence > 0) {
            return $sequence > $existing->client_sequence;
        }

        if ($timestamp && $existing->client_timestamp) {
            return $timestamp->getTimestamp() > $existing->client_timestamp->getTimestamp();
        }

        // The stored answer carries ordering info and the incoming one does
        // not: the incoming one is the older, dumber client. Keep what we have.
        if (!$timestamp && $sequence === 0 && ($existing->client_timestamp || $existing->client_sequence > 0)) {
            return false;
        }

        return true;
    }

    private function parseTimestamp($value): ?\Illuminate\Support\Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Submission and grading
    // ------------------------------------------------------------------

    /**
     * Finalise and grade. Idempotent: a network retry of the submit call
     * returns the already-graded attempt rather than double-grading it.
     */
    public function submitAttempt(CbtAttempt $attempt, string $reason = 'manual_submit'): CbtAttempt
    {
        if ($attempt->isFinalised()) {
            return $attempt;
        }

        $exam = CbtExam::withoutGlobalScopes()->findOrFail($attempt->exam_id);

        return DB::transaction(function () use ($attempt, $exam, $reason) {
            $questions = QuestionBankItem::withoutGlobalScopes()
                ->whereIn('id', $attempt->question_order ?? [])
                ->get()
                ->keyBy('id');

            $links = CbtExamQuestion::where('exam_id', $exam->id)
                ->whereIn('question_id', $questions->keys())
                ->get()
                ->keyBy('question_id');

            $answers = CbtAttemptAnswer::where('attempt_id', $attempt->id)->get()->keyBy('question_id');

            $rawScore = 0.0;
            $maxScore = 0.0;
            $needsManual = false;

            foreach ($questions as $questionId => $question) {
                $link = $links->get($questionId);
                $marks = $link ? $link->effectiveMarks($question) : (float) $question->marks;
                $negative = $exam->negative_marking
                    ? ($link ? $link->effectiveNegativeMarks($question) : (float) $question->negative_marks)
                    : 0.0;

                $maxScore += $marks;

                $answer = $answers->get($questionId);
                $result = $this->grader->grade($question, $answer?->response, $marks, $negative);

                if ($result['requires_manual_grading']) {
                    $needsManual = true;
                }

                $rawScore += $result['awarded_marks'];

                if ($answer) {
                    $answer->update([
                        'is_correct' => $result['is_correct'],
                        'awarded_marks' => $result['awarded_marks'],
                        'graded_by' => $result['requires_manual_grading'] ? null : 'auto',
                    ]);
                } elseif (!$result['requires_manual_grading']) {
                    // Record unanswered questions explicitly so item analysis
                    // can tell "left blank" from "never served".
                    CbtAttemptAnswer::create([
                        'attempt_id' => $attempt->id,
                        'question_id' => $questionId,
                        'response' => null,
                        'is_correct' => false,
                        'awarded_marks' => 0,
                        'graded_by' => 'auto',
                    ]);
                }

                $this->recordItemStatistics($question, $result);

                // Per-student topic mastery (doc §7.11). The item statistics
                // above describe the *question*; this describes the *child*,
                // and is what the AI tutor and the student dashboard read to
                // say "you are weak on quadratic equations".
                if (! $result['requires_manual_grading']) {
                    \App\Models\StudentTopicMastery::recordAnswer(
                        (int) $attempt->school_id,
                        (int) $attempt->student_id,
                        $question->subject_id ? (int) $question->subject_id : null,
                        $question->topic,
                        (bool) $result['is_correct']
                    );
                }
            }

            // A negative-marking paper can drive a candidate below zero; a
            // negative report-card score is not a thing, so floor it.
            $rawScore = max(0.0, $rawScore);
            $percentage = $maxScore > 0 ? round(($rawScore / $maxScore) * 100, 2) : 0.0;
            $now = now();

            $attempt->update([
                'status' => $needsManual ? 'submitted' : 'graded',
                'submitted_at' => $now,
                'graded_at' => $needsManual ? null : $now,
                'raw_score' => round($rawScore, 2),
                'max_score' => round($maxScore, 2),
                'percentage' => $percentage,
                'grade' => $this->waecGrade($percentage),
                'requires_manual_grading' => $needsManual,
                'time_spent_seconds' => $attempt->started_at
                    ? $now->getTimestamp() - $attempt->started_at->getTimestamp()
                    : null,
            ]);

            $this->logEvent($attempt, $reason, ['percentage' => $percentage]);

            return $attempt->fresh();
        });
    }

    /**
     * Sweep attempts whose deadline passed without a submit — a candidate
     * whose machine died, or who simply closed the tab. Run from a scheduled
     * command; without it those attempts sit "in_progress" forever and the
     * student can never re-enter.
     *
     * @return int number of attempts closed
     */
    public function expireOverdueAttempts(?int $schoolId = null): int
    {
        $query = CbtAttempt::withoutGlobalScopes()
            ->where('status', 'in_progress')
            ->whereNotNull('server_deadline_at')
            ->where('server_deadline_at', '<', now());

        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }

        $closed = 0;
        foreach ($query->cursor() as $attempt) {
            $this->submitAttempt($attempt, 'auto_submit');
            $closed++;
        }

        return $closed;
    }

    /**
     * Running counters for classical item analysis. Cheap to maintain here;
     * expensive to compute later across every attempt ever sat.
     */
    private function recordItemStatistics(QuestionBankItem $question, array $result): void
    {
        if ($result['requires_manual_grading']) {
            return;
        }

        QuestionBankItem::withoutGlobalScopes()
            ->where('id', $question->id)
            ->update([
                'times_answered' => DB::raw('times_answered + 1'),
                'times_correct' => DB::raw('times_correct + ' . ($result['is_correct'] ? 1 : 0)),
            ]);
    }

    /** WAEC nine-point scale — not letter grades, not a GPA. */
    public function waecGrade(float $percentage): string
    {
        return match (true) {
            $percentage >= 75 => 'A1',
            $percentage >= 70 => 'B2',
            $percentage >= 65 => 'B3',
            $percentage >= 60 => 'C4',
            $percentage >= 55 => 'C5',
            $percentage >= 50 => 'C6',
            $percentage >= 45 => 'D7',
            $percentage >= 40 => 'E8',
            default => 'F9',
        };
    }

    public function logEvent(CbtAttempt $attempt, string $type, array $metadata = []): void
    {
        CbtAttemptEvent::create([
            'attempt_id' => $attempt->id,
            'event_type' => $type,
            'occurred_at' => now(),
            'metadata' => $metadata ?: null,
        ]);
    }

    // ------------------------------------------------------------------
    // Deterministic shuffle
    // ------------------------------------------------------------------

    /**
     * Fisher-Yates driven by a seeded 32-bit LCG.
     *
     * Deliberately not `shuffle()` with `mt_srand()`: that mutates global
     * random state (poisoning anything else in the request that needs
     * randomness) and PHP has changed its Mt19937 implementation between
     * versions, which would silently re-order a resumed candidate's paper
     * after a server upgrade. This is self-contained and stable forever.
     */
    public function seededShuffle(Collection $items, int $seed): Collection
    {
        $values = $items->values()->all();
        $state = $seed % 2147483647;
        if ($state <= 0) {
            $state += 2147483646;
        }

        for ($i = count($values) - 1; $i > 0; $i--) {
            $state = ($state * 16807) % 2147483647; // MINSTD
            $j = $state % ($i + 1);
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }

        return collect($values);
    }
}
