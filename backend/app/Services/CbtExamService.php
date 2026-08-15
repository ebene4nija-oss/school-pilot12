<?php

namespace App\Services;

use App\Models\CbtAttempt;
use App\Models\CbtAttemptAnswer;
use App\Models\CbtAttemptEvent;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\CbtQuestionGroup;
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

            // A paper pre-issued into an offline bundle is adopted rather than
            // duplicated. Without this, a candidate whose relay bundle was
            // built yesterday and who then sits online today would get a
            // second attempt row while the provisioned one sat unused —
            // burning an allowance and splitting their answers across two
            // attempts.
            $provisioned = $existing->firstWhere('status', CbtAttempt::STATUS_PROVISIONED);
            if ($provisioned) {
                return $this->activateProvisionedAttempt($provisioned, $exam, $now, $context);
            }

            $seed = random_int(1, PHP_INT_MAX - 1);
            $questionIds = $this->composePaper($exam, $seed);

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
                'seed' => $seed,
                'question_order' => $questionIds,
                'order_is_final' => true,
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
     * Turn a pre-issued offline attempt into a live one.
     *
     * The paper — order, seed, the lot — was fixed when the bundle was built
     * and is not recomputed here. A candidate who sat the first ten questions
     * in the lab and finished online must meet the same paper on both.
     */
    public function activateProvisionedAttempt(
        CbtAttempt $attempt,
        CbtExam $exam,
        ?\DateTimeInterface $now = null,
        array $context = []
    ): CbtAttempt {
        $now = $now ? \Illuminate\Support\Carbon::instance($now) : now();

        $extraTime = (int) ($context['extra_time_minutes'] ?? $attempt->extra_time_minutes ?? 0);

        // The deadline was pre-computed at bundle build against a policy, not
        // against a start time nobody could know in advance. It is recomputed
        // from the real start, then still clamped to the exam window.
        $deadline = $now->copy()->addMinutes($exam->duration_minutes + $extraTime);

        if ($exam->closes_at && $deadline->greaterThan($exam->closes_at)) {
            $deadline = $exam->closes_at->copy();
        }

        $attempt->update([
            'status' => 'in_progress',
            'started_at' => $now,
            'server_deadline_at' => $deadline,
            'extra_time_minutes' => $extraTime,
            'device_fingerprint' => $context['device_fingerprint'] ?? $attempt->device_fingerprint,
            'ip_address' => $context['ip_address'] ?? $attempt->ip_address,
        ]);

        $this->logEvent($attempt, 'resumed', [
            'from' => 'provisioned',
            'at' => $now->toIso8601String(),
        ]);

        return $attempt->fresh();
    }

    // ------------------------------------------------------------------
    // Composing a paper: selection, grouping, order
    // ------------------------------------------------------------------

    /**
     * The flat, final question order an attempt will sit.
     *
     * Three rules run here, in this order, and all three run **once,
     * server-side** so the offline client never re-derives them (§6.6, §10):
     *
     * 1. **Selection.** `questions_per_attempt` draws N — but it draws whole
     *    groups. Handing a candidate part (c) of a comprehension without (a)
     *    and (b) is not a shorter paper, it is a broken one.
     * 2. **Shuffle at group level.** A flat shuffle would scatter a passage's
     *    sub-questions across the paper. Groups move as single units;
     *    ungrouped questions shuffle among them as units of one.
     * 3. **Optionally shuffle within each group**, off by default, because
     *    sub-questions frequently build on each other.
     *
     * @return array<int,int> question ids, in the order they will be served
     */
    public function composePaper(CbtExam $exam, int $seed): array
    {
        $units = $this->paperUnits($exam);

        if ($units === []) {
            return [];
        }

        $units = $this->drawUnits($units, $exam, $seed);

        if ($exam->shuffle_questions) {
            $units = $this->seededShuffle(collect($units), $seed)->all();
        }

        if ($exam->shuffle_within_group) {
            $units = array_map(function (array $unit) use ($seed) {
                // Seeded per group so shuffling group 3 does not depend on how
                // many groups came before it.
                return count($unit['ids']) > 1 && $unit['group_id'] !== null
                    ? ['group_id' => $unit['group_id'], 'ids' => $this->seededShuffle(collect($unit['ids']), $seed + $unit['group_id'])->all()]
                    : $unit;
            }, $units);
        }

        return array_values(array_merge(...array_column($units, 'ids')));
    }

    /**
     * The exam's questions as indivisible units: one unit per group, one per
     * ungrouped question. A group takes the position of its earliest member,
     * so an author's `order_index` still decides where the passage sits.
     *
     * @return array<int,array{group_id: int|null, ids: array<int,int>}>
     */
    private function paperUnits(CbtExam $exam): array
    {
        $questionIds = CbtExamQuestion::where('exam_id', $exam->id)
            ->orderBy('order_index')
            ->pluck('question_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($questionIds === []) {
            return [];
        }

        $groupOf = QuestionBankItem::withoutGlobalScopes()
            ->whereIn('id', $questionIds)
            ->pluck('group_id', 'id');

        $sequenceOf = QuestionBankItem::withoutGlobalScopes()
            ->whereIn('id', $questionIds)
            ->pluck('group_sequence', 'id');

        $units = [];
        $groupUnitIndex = [];

        foreach ($questionIds as $questionId) {
            $groupId = $groupOf->get($questionId);
            $groupId = $groupId === null ? null : (int) $groupId;

            if ($groupId === null) {
                $units[] = ['group_id' => null, 'ids' => [$questionId]];
                continue;
            }

            if (! isset($groupUnitIndex[$groupId])) {
                $groupUnitIndex[$groupId] = count($units);
                $units[] = ['group_id' => $groupId, 'ids' => []];
            }

            $units[$groupUnitIndex[$groupId]]['ids'][] = $questionId;
        }

        // Within a group the authored sequence wins; questions with no
        // sequence trail behind in id order rather than landing arbitrarily.
        foreach ($units as $index => $unit) {
            if ($unit['group_id'] === null || count($unit['ids']) < 2) {
                continue;
            }

            $ids = $unit['ids'];
            usort($ids, function ($a, $b) use ($sequenceOf) {
                $sa = $sequenceOf->get($a);
                $sb = $sequenceOf->get($b);

                if ($sa === null && $sb === null) {
                    return $a <=> $b;
                }
                if ($sa === null) {
                    return 1;
                }
                if ($sb === null) {
                    return -1;
                }

                return ((int) $sa <=> (int) $sb) ?: ($a <=> $b);
            });

            $units[$index]['ids'] = $ids;
        }

        return $units;
    }

    /**
     * Draw `questions_per_attempt` questions — a different subset per attempt,
     * the standard defence against candidates comparing answers across a lab.
     *
     * Selection is by whole unit. Where N cannot be composed exactly out of
     * whole groups the draw **overshoots to the nearest group boundary**
     * rather than splitting one: a candidate sitting 21 questions instead of
     * 20 is a rounding annoyance, a candidate sitting half a passage is a
     * complaint from a parent. `CbtController::publishExam` warns the author
     * about this at publish time.
     *
     * @param array<int,array{group_id: int|null, ids: array<int,int>}> $units
     * @return array<int,array{group_id: int|null, ids: array<int,int>}>
     */
    private function drawUnits(array $units, CbtExam $exam, int $seed): array
    {
        $target = (int) $exam->questions_per_attempt;
        $total = array_sum(array_map(fn ($unit) => count($unit['ids']), $units));

        if ($target <= 0 || $target >= $total) {
            return $units;
        }

        // Seeded rather than `shuffle()`: the draw is then reproducible from
        // the attempt row alone, which is what lets an invigilator answer
        // "which questions did this candidate actually get?" during a dispute.
        $order = $this->seededShuffle(collect(array_keys($units)), $seed)->all();

        $chosen = [];
        $count = 0;

        foreach ($order as $index) {
            if ($count >= $target) {
                break;
            }

            $chosen[] = $index;
            $count += count($units[$index]['ids']);
        }

        // Restore authored order among the chosen units; the shuffle step
        // above owns presentation order, this step only owns selection.
        sort($chosen);

        return array_values(array_map(fn ($index) => $units[$index], $chosen));
    }

    /**
     * How many questions an attempt at this exam will actually contain.
     *
     * With groups and a subset draw the answer is no longer
     * `questions_per_attempt` — it is that number rounded up to a group
     * boundary. Authoring and publishing surfaces quote this so nobody
     * discovers the overshoot from a candidate.
     */
    public function effectiveQuestionCount(CbtExam $exam): int
    {
        $units = $this->paperUnits($exam);

        if ($units === []) {
            return 0;
        }

        $drawn = $this->drawUnits($units, $exam, 1);

        return array_sum(array_map(fn ($unit) => count($unit['ids']), $drawn));
    }

    // ------------------------------------------------------------------
    // Serving the paper
    // ------------------------------------------------------------------

    /**
     * Every bank item attached to an exam, without the tenant scope — used to
     * build media manifests and to count a paper's shape.
     *
     * @return Collection<int,QuestionBankItem>
     */
    public function questionsForExam(CbtExam $exam): Collection
    {
        return QuestionBankItem::withoutGlobalScopes()
            ->whereIn('id', CbtExamQuestion::where('exam_id', $exam->id)->pluck('question_id'))
            ->get();
    }

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

        // Attempts created since grouping landed store the final flat order
        // and are served exactly as stored (§10). Older attempts stored an
        // unordered list and had the shuffle applied here — re-deriving that
        // differently would hand a resumed candidate a paper in an order they
        // have never seen, so the old path stays exactly as it was.
        if (! $attempt->order_is_final && $exam->shuffle_questions) {
            $ordered = $this->seededShuffle($ordered, $attempt->seed);
        }

        $links = CbtExamQuestion::where('exam_id', $exam->id)
            ->whereIn('question_id', $questionIds)
            ->get()
            ->keyBy('question_id');

        $groups = $this->hydrateGroups($ordered);
        $assets = $this->media->hydrateForQuestions($ordered);
        $saved = CbtAttemptAnswer::where('attempt_id', $attempt->id)->get()->keyBy('question_id');

        // "Question 3 of 6 in this passage" needs both numbers, and the
        // candidate needs them from the order they are actually sitting, not
        // from the authored sequence.
        $groupSizes = [];
        foreach ($ordered as $question) {
            if ($question->group_id) {
                $groupId = (int) $question->group_id;
                $groupSizes[$groupId] = ($groupSizes[$groupId] ?? 0) + 1;
            }
        }

        $groupSeen = [];

        $payload = [];
        $number = 1;

        foreach ($ordered as $question) {
            $link = $links->get($question->id);
            $row = $this->presentQuestion(
                $question,
                $attempt,
                $exam,
                $link,
                $assets,
                $number++,
                $saved->get($question->id)
            );

            if ($question->group_id && isset($groups[$question->group_id])) {
                $groupId = (int) $question->group_id;
                $groupSeen[$groupId] = ($groupSeen[$groupId] ?? 0) + 1;

                $row['group'] = $groups[$groupId] + [
                    'position' => $groupSeen[$groupId],
                    'of' => $groupSizes[$groupId] ?? 1,
                ];
            }

            $payload[] = $row;
        }

        return $payload;
    }

    /**
     * Stimulus payloads for every group represented in a paper, keyed by id.
     *
     * Loaded once per paper rather than once per sub-question: a comprehension
     * passage attached to six questions is one passage, and shipping it six
     * times is both wasteful and a way for the six copies to disagree.
     *
     * @param Collection<int,QuestionBankItem> $questions
     * @return array<int,array>
     */
    private function hydrateGroups(Collection $questions): array
    {
        $groupIds = $questions->pluck('group_id')->filter()->unique()->values();

        if ($groupIds->isEmpty()) {
            return [];
        }

        $groups = CbtQuestionGroup::withoutGlobalScopes()
            ->whereIn('id', $groupIds)
            ->get();

        $assets = $this->media->hydrateForQuestions($groups);

        $out = [];
        foreach ($groups as $group) {
            $media = [];
            foreach ($group->media ?? [] as $reference) {
                $asset = $assets[$reference['asset_id'] ?? null] ?? null;
                if ($asset) {
                    $media[] = ['role' => $reference['role'] ?? 'stimulus', 'position' => $reference['position'] ?? 0] + $asset;
                }
            }

            usort($media, fn ($a, $b) => $a['position'] <=> $b['position']);

            $out[(int) $group->id] = $group->toCandidatePayload($media);
        }

        return $out;
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
     * The public half of a question's `answer_schema`, for callers outside the
     * live paper — chiefly the offline bundle builder.
     *
     * Deliberately the same method the live paper uses rather than a parallel
     * one. Two implementations of "what may a candidate see" would eventually
     * disagree, and the copy that disagreed would be the one shipped to a
     * machine in an exam room.
     */
    public function candidateInteraction(QuestionBankItem $question): array
    {
        return $this->publicAnswerSchema($question);
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
            // The manually-graded family (§6.3). `rubric` lives in the same
            // JSON column and is conspicuously absent from this list: it is
            // marking-scheme data in exactly the sense `correct_answer` is —
            // "2 marks for stating the thesis" tells a candidate what to
            // write. This projection is also what the offline bundle ships,
            // so the rubric never reaches a machine in the exam room.
            'theory' => array_filter([
                'response_format' => $this->responseFormat($schema),
                'answer_mode' => in_array($schema['answer_mode'] ?? null, QuestionBankItem::ANSWER_MODES, true)
                    ? $schema['answer_mode']
                    : 'on_screen',
                'expected_words' => isset($schema['expected_words']) ? (int) $schema['expected_words'] : null,
                'max_words' => isset($schema['max_words']) ? (int) $schema['max_words'] : null,
                'allow_working_photo' => array_key_exists('allow_working_photo', $schema)
                    ? (bool) $schema['allow_working_photo']
                    : null,
                // Retained under its original name: clients written before
                // `allow_working_photo` existed still read this one.
                'allow_image_answer' => (bool) ($schema['allow_image_answer'] ?? true),
                // Labelled sub-fields for (a), (b), (c) — the prompts only.
                'parts' => $this->structuredParts($schema),
            ], fn ($value) => $value !== null),
            default => [],
        };
    }

    private function responseFormat(array $schema): string
    {
        $format = $schema['response_format'] ?? 'short_answer';

        return in_array($format, QuestionBankItem::RESPONSE_FORMATS, true) ? $format : 'short_answer';
    }

    /**
     * The prompts of a `structured` theory question, without whatever the
     * author wrote next to them. An author who puts an expected answer on a
     * part must not have it served alongside the prompt.
     */
    private function structuredParts(array $schema): ?array
    {
        if ($this->responseFormat($schema) !== 'structured') {
            return null;
        }

        return collect($schema['parts'] ?? [])
            ->map(fn ($part, $key) => array_filter([
                'key' => is_array($part) ? ($part['key'] ?? (string) $key) : (string) $key,
                'prompt' => is_array($part) ? ($part['prompt'] ?? $part['label'] ?? '') : (string) $part,
                'marks' => is_array($part) && isset($part['marks']) ? (float) $part['marks'] : null,
            ], fn ($value) => $value !== null))
            ->values()
            ->all();
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
                } else {
                    // Record unanswered questions explicitly so item analysis
                    // can tell "left blank" from "never served".
                    //
                    // Manually-graded questions get a row here too, and that
                    // is not cosmetic. An `on_paper` theory question stores no
                    // response by design (§6.4) — the answer is in a booklet.
                    // Without a row, `gradeAttempt` would have nothing to
                    // update, the teacher's mark would vanish silently, and
                    // the outstanding-work check would report the attempt
                    // fully graded. Every question served gets a row; a null
                    // `graded_by` is what "still owed a human" means.
                    CbtAttemptAnswer::create([
                        'attempt_id' => $attempt->id,
                        'question_id' => $questionId,
                        'response' => null,
                        'is_correct' => $result['requires_manual_grading'] ? null : false,
                        'awarded_marks' => $result['requires_manual_grading'] ? null : 0,
                        'graded_by' => $result['requires_manual_grading'] ? null : 'auto',
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
