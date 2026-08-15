<?php

namespace App\Services;

use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\CbtExamQuestion;
use App\Models\CbtOfflineBundle;
use App\Models\CbtQuestionGroup;
use App\Models\QuestionBankItem;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the encrypted paper a lab relay carries into a room with no internet
 * (docs/offline-cbt-client.md §9.1).
 *
 * The backend has no endpoint that returns question text ahead of an attempt —
 * papers are assembled only inside `startAttempt`, and the existing
 * `offlinePackage` endpoint is media-only for candidates by deliberate design.
 * That rule collides head-on with "no network during the exam": you cannot
 * simultaneously have no connectivity in the room and no question text in the
 * room. What gives is the second one, **under encryption**.
 *
 * So this service is the one exception, and it is bounded by three properties
 * that are the whole reason it is allowed to exist:
 *
 * **The bundle carries no marking scheme.** No `correct_answer`, no `rubric`,
 * no `explanation` — not filtered out late, but never assembled in. Offline
 * exams grade server-side after sync (§5.4), which is what makes that
 * possible: there is nothing on the relay worth stealing except the questions,
 * and a candidate who steals the questions during the exam is a candidate
 * reading the exam.
 *
 * **The key is not in the bundle and not on the relay.** The ciphertext sits
 * on a lab laptop overnight; the key is released on exam morning through a
 * separate, audited endpoint, and lives in the relay's memory only.
 *
 * **The paper is fixed here, not derived there.** Question order, per-candidate
 * subsets and group flattening are all resolved server-side and shipped flat,
 * so no second implementation of the ordering rules can drift from this one
 * (§10).
 */
class CbtOfflineBundleService
{
    /**
     * Bundle wire format. A client that meets a version it does not know must
     * refuse the paper and say so plainly at provision time, rather than
     * failing obscurely at unlock on exam morning (§17).
     */
    public const FORMAT_VERSION = 1;

    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    /** Excludes 0/O/1/I/L — an invigilator reads these aloud across a room. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(
        private CbtExamService $exams,
        private CbtMediaService $media
    ) {
    }

    // ------------------------------------------------------------------
    // Issuance
    // ------------------------------------------------------------------

    /**
     * Build an encrypted bundle for one exam and one relay.
     *
     * @param array{relay_identity?: string|null, override_existing?: bool, student_ids?: array<int,int>} $options
     * @return array{bundle: CbtOfflineBundle, envelope: array, key: string}
     *
     * @throws \RuntimeException when the exam is not fit to be taken offline
     */
    public function build(CbtExam $exam, User $issuer, array $options = []): array
    {
        $this->assertBundlable($exam, $options);

        $roster = $this->eligibleStudents($exam, $options['student_ids'] ?? null);

        if ($roster->isEmpty()) {
            throw new \RuntimeException('No eligible candidates found for this exam. Check the class it was set for.');
        }

        $questionIds = CbtExamQuestion::where('exam_id', $exam->id)->pluck('question_id');

        if ($questionIds->isEmpty()) {
            throw new \RuntimeException('This exam has no questions attached yet.');
        }

        return DB::transaction(function () use ($exam, $issuer, $options, $roster) {
            $bundleId = (string) Str::uuid();
            $key = random_bytes(32);

            // Every candidate's paper is composed now, once, on the server.
            // The relay hands each candidate a flat list of question ids and
            // does no selection or shuffling of its own.
            $entries = [];
            $attemptIds = [];

            foreach ($roster as $student) {
                $entry = $this->provisionAttempt($exam, $student);
                $entries[] = $entry;
                $attemptIds[] = $entry['attempt_id'];
            }

            // Only the questions actually drawn for somebody need to travel.
            // With `questions_per_attempt` on a large bank that is a materially
            // smaller bundle to move over a phone hotspot.
            $servedIds = collect($entries)->pluck('question_order')->flatten()->unique()->values();

            $questions = QuestionBankItem::withoutGlobalScopes()
                ->whereIn('id', $servedIds)
                ->get();

            $payload = [
                'format_version' => self::FORMAT_VERSION,
                'exam' => $this->examSettings($exam),
                'groups' => $this->groupPayload($questions),
                'questions' => $this->questionPayload($questions, $exam),
                'roster' => $entries,
            ];

            $header = [
                'bundle_id' => $bundleId,
                'exam_id' => (int) $exam->id,
                'school_id' => (int) $exam->school_id,
                'format_version' => self::FORMAT_VERSION,
                'question_count' => $questions->count(),
                'attempt_count' => count($entries),
                'opens_at' => $exam->opens_at?->toIso8601String(),
                'closes_at' => $exam->closes_at?->toIso8601String(),
                'built_at' => now()->toIso8601String(),
            ];

            $sealed = $this->seal($payload, $key, $header);

            $bundle = CbtOfflineBundle::create([
                'school_id' => $exam->school_id,
                'exam_id' => $exam->id,
                'bundle_id' => $bundleId,
                'key_id' => (string) Str::uuid(),
                'format_version' => self::FORMAT_VERSION,
                'content_key' => base64_encode($key),
                'attempt_ids' => $attemptIds,
                'question_count' => $questions->count(),
                'attempt_count' => count($entries),
                'ciphertext_bytes' => strlen($sealed['ciphertext']),
                'relay_identity' => $options['relay_identity'] ?? null,
                'built_by' => $issuer->id,
                'built_at' => now(),
            ]);

            CbtAttempt::withoutGlobalScopes()
                ->whereIn('id', $attemptIds)
                ->update(['offline_bundle_id' => $bundle->id]);

            return [
                'bundle' => $bundle,
                'key' => base64_encode($key),
                'envelope' => [
                    'bundle_id' => $bundleId,
                    'key_id' => $bundle->key_id,
                    'format_version' => self::FORMAT_VERSION,
                    'built_at' => $bundle->built_at->toIso8601String(),
                    // Plaintext, and non-sensitive on purpose: an admin needs
                    // to see what they are about to carry into a lab without
                    // holding the key to look inside it.
                    'header' => $header,
                    'cipher' => [
                        'algorithm' => self::CIPHER,
                        'compression' => 'gzip',
                        'iv' => base64_encode($sealed['iv']),
                        'tag' => base64_encode($sealed['tag']),
                        'ciphertext' => base64_encode($sealed['ciphertext']),
                    ],
                ],
            ];
        });
    }

    /**
     * @throws \RuntimeException
     */
    private function assertBundlable(CbtExam $exam, array $options): void
    {
        if (! $exam->allow_offline) {
            throw new \RuntimeException('This exam is not marked as available offline.');
        }

        if ($exam->status !== 'published') {
            throw new \RuntimeException('Only a published exam can be bundled. A draft paper may still change.');
        }

        // §15: the deadline in the bundle must be a real instant the relay can
        // enforce with no clock of its own to trust. Without a scheduled
        // start there is nothing to compute one from, and "duration from
        // whenever the candidate clicked" is exactly the local-clock
        // arithmetic the offline client is forbidden to do.
        if (! $exam->opens_at) {
            throw new \RuntimeException('Set an opening time before bundling. An offline paper needs a scheduled start so the relay can carry a real deadline.');
        }

        $live = CbtOfflineBundle::where('exam_id', $exam->id)->whereNull('revoked_at')->first();

        if ($live && ! ($options['override_existing'] ?? false)) {
            // Two relays serving one exam is a split brain: two rosters, two
            // answer queues, and a candidate who can sit the paper twice.
            throw new \RuntimeException(sprintf(
                'A bundle for this exam was already issued to "%s" on %s. Revoke it, or re-issue with override_existing to run a second relay deliberately.',
                $live->relay_identity ?: 'an unnamed relay',
                $live->built_at->toDayDateTimeString()
            ));
        }
    }

    /**
     * Candidates entitled to sit this paper. Class binding is the same rule
     * `CbtExamPolicy::sit` applies online — a bundle must not become a way to
     * put an SS3 mock in front of a JSS1 pupil.
     *
     * @param array<int,int>|null $studentIds optional narrowing to one room
     * @return Collection<int,Student>
     */
    private function eligibleStudents(CbtExam $exam, ?array $studentIds): Collection
    {
        return Student::withoutGlobalScopes()
            ->where('school_id', $exam->school_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->when($exam->class_id, fn ($q) => $q->where('class_id', $exam->class_id))
            ->when($studentIds, fn ($q) => $q->whereIn('id', $studentIds))
            ->with('user:id,name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Pre-issue one attempt.
     *
     * `provisioned` is a status of its own rather than an early `in_progress`
     * precisely so it does not lie: it must not count against `max_attempts`,
     * must not be swept by the overdue-attempt sweeper, and must be
     * reclaimable if the exam is cancelled. `startAttempt` adopts it if the
     * candidate ends up sitting online instead.
     *
     * @return array the roster entry that travels inside the bundle
     */
    private function provisionAttempt(CbtExam $exam, Student $student): array
    {
        $existing = CbtAttempt::withoutGlobalScopes()
            ->where('exam_id', $exam->id)
            ->where('student_id', $student->id)
            ->orderByDesc('attempt_number')
            ->get();

        // Re-issuing a bundle must not mint a second paper for a candidate who
        // already has an unsat one, or their two relays would disagree about
        // which attempt is theirs.
        $attempt = $existing->firstWhere('status', CbtAttempt::STATUS_PROVISIONED);

        if (! $attempt) {
            $seed = random_int(1, PHP_INT_MAX - 1);

            $attempt = CbtAttempt::create([
                'school_id' => $exam->school_id,
                'exam_id' => $exam->id,
                'student_id' => $student->id,
                'attempt_number' => (int) $existing->max('attempt_number') + 1,
                'seed' => $seed,
                'question_order' => $this->exams->composePaper($exam, $seed),
                'order_is_final' => true,
                'status' => CbtAttempt::STATUS_PROVISIONED,
                'server_deadline_at' => $this->provisionedDeadline($exam),
            ]);
        }

        return [
            'attempt_id' => (int) $attempt->id,
            'student_id' => (int) $student->id,
            'candidate_name' => $student->user?->name,
            'admission_number' => $student->admission_number,
            'attempt_number' => (int) $attempt->attempt_number,
            'seed' => (int) $attempt->seed,
            'question_order' => array_map('intval', $attempt->question_order ?? []),
            'server_deadline_at' => $attempt->server_deadline_at?->toIso8601String(),
            // What the candidate types at the seat to claim their paper from
            // the relay. It exists only inside the ciphertext — the server
            // keeps no copy, because the server is never asked to verify it.
            'relay_code' => $this->relayCode(),
        ];
    }

    /**
     * The deadline the relay will enforce, computed from the scheduled start
     * rather than from whenever a candidate happens to click begin.
     *
     * A late candidate loses the time they were late by, exactly as in a hall
     * where the clock on the wall does not restart for them.
     */
    private function provisionedDeadline(CbtExam $exam): \Illuminate\Support\Carbon
    {
        $deadline = $exam->opens_at->copy()->addMinutes($exam->duration_minutes);

        if ($exam->closes_at && $deadline->greaterThan($exam->closes_at)) {
            $deadline = $exam->closes_at->copy();
        }

        return $deadline;
    }

    private function relayCode(): string
    {
        $code = '';
        $max = strlen(self::CODE_ALPHABET) - 1;

        for ($i = 0; $i < 8; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    // ------------------------------------------------------------------
    // Payload
    // ------------------------------------------------------------------

    /** Exam settings the offline client must honour, and nothing else. */
    private function examSettings(CbtExam $exam): array
    {
        return [
            'id' => (int) $exam->id,
            'title' => $exam->title,
            'instructions' => $exam->instructions,
            'content_format' => $exam->content_format ?? 'plain',
            'duration_minutes' => (int) $exam->duration_minutes,
            'opens_at' => $exam->opens_at?->toIso8601String(),
            'closes_at' => $exam->closes_at?->toIso8601String(),
            'shuffle_questions' => (bool) $exam->shuffle_questions,
            'shuffle_options' => (bool) $exam->shuffle_options,
            'shuffle_within_group' => (bool) $exam->shuffle_within_group,
            'questions_per_attempt' => $exam->questions_per_attempt ? (int) $exam->questions_per_attempt : null,
            'max_attempts' => (int) $exam->max_attempts,
            'negative_marking' => (bool) $exam->negative_marking,
            'pass_mark' => (float) $exam->pass_mark,
            'total_marks' => (float) $exam->total_marks,
            'integrity_settings' => $exam->integrity_settings,
            // Offline papers never show a score in the room (§5.4). Stated in
            // the bundle so the client does not have to infer it.
            'show_results_immediately' => false,
        ];
    }

    /**
     * Shared stimuli. Loaded once per bundle however many sub-questions hang
     * off them — a comprehension passage shipped six times is six copies that
     * can disagree.
     *
     * @param Collection<int,QuestionBankItem> $questions
     */
    private function groupPayload(Collection $questions): array
    {
        $groupIds = $questions->pluck('group_id')->filter()->unique()->values();

        if ($groupIds->isEmpty()) {
            return [];
        }

        return CbtQuestionGroup::withoutGlobalScopes()
            ->whereIn('id', $groupIds)
            ->get()
            ->map(fn (CbtQuestionGroup $group) => [
                'group_id' => (int) $group->id,
                'title' => $group->title,
                'stimulus' => $group->stimulus,
                'content_format' => $group->content_format ?? 'plain',
                'instructions' => $group->instructions,
                'media' => $this->mediaReferences($group->media),
            ])
            ->values()
            ->all();
    }

    /**
     * The questions themselves.
     *
     * Built by naming every field that goes in, never by taking a model and
     * removing the dangerous ones. `correct_answer`, `explanation` and the
     * answer half of `answer_schema` are not filtered here — they are simply
     * never reached, so a column added to `question_bank` next year cannot
     * arrive in a lab by default.
     *
     * @param Collection<int,QuestionBankItem> $questions
     */
    private function questionPayload(Collection $questions, CbtExam $exam): array
    {
        $links = CbtExamQuestion::where('exam_id', $exam->id)
            ->whereIn('question_id', $questions->pluck('id'))
            ->get()
            ->keyBy('question_id');

        return $questions->map(function (QuestionBankItem $question) use ($links) {
            $link = $links->get($question->id);

            return [
                'question_id' => (int) $question->id,
                'question_type' => $question->question_type,
                'content_format' => $question->content_format ?? 'plain',
                'question' => $question->question,
                'topic' => $question->topic,
                'section' => $link?->section,
                'marks' => $link ? $link->effectiveMarks($question) : (float) $question->marks,
                'negative_marks' => $link ? $link->effectiveNegativeMarks($question) : (float) $question->negative_marks,
                'group_id' => $question->group_id ? (int) $question->group_id : null,
                'group_sequence' => $question->group_sequence !== null ? (int) $question->group_sequence : null,
                // Canonical order. The client shuffles these per attempt with
                // `seed + question_id`, matching `presentOptions` exactly —
                // that reimplementation is what the parity vectors in
                // tests/fixtures/cbt-shuffle-parity.json exist to police.
                'options' => $this->optionPayload($question),
                'media' => $this->mediaReferences($question->media),
                // Geometry, label pools, word caps, answer mode. The public
                // half of answer_schema and nothing else.
                'interaction' => $this->exams->candidateInteraction($question),
                'answer_mode' => $question->answerMode(),
            ];
        })->values()->all();
    }

    private function optionPayload(QuestionBankItem $question): array
    {
        $raw = $question->options;

        if (empty($raw) || ! is_array($raw)) {
            return [];
        }

        $mediaByRole = collect($question->media ?? [])->groupBy('role');

        return collect($raw)->map(function ($value, $key) use ($mediaByRole) {
            if (is_array($value)) {
                $optionKey = (string) ($value['key'] ?? $key);
                $text = $value['text'] ?? $value['label'] ?? '';
            } else {
                $optionKey = (string) $key;
                $text = (string) $value;
            }

            $refs = $mediaByRole->get('option:' . $optionKey);

            return [
                'key' => $optionKey,
                'text' => $text,
                'image_asset_id' => $refs && $refs->isNotEmpty() ? (int) $refs->first()['asset_id'] : null,
            ];
        })->values()->all();
    }

    /**
     * Media travels as asset ids only. The files themselves come from the
     * existing staff offline-package manifest, which the relay fetches
     * separately and verifies by checksum — encrypting them would protect
     * nothing (they are already reachable to any authenticated candidate
     * before `opens_at` by design) and would cost the relay its cache.
     *
     * The `explanation` role is dropped: an explanation is the answer.
     */
    private function mediaReferences(?array $media): array
    {
        return collect($media ?? [])
            ->reject(fn ($reference) => ($reference['role'] ?? 'stem') === 'explanation')
            ->map(fn ($reference) => [
                'asset_id' => (int) $reference['asset_id'],
                'role' => $reference['role'] ?? 'stem',
                'position' => (int) ($reference['position'] ?? 0),
            ])
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // Sealing
    // ------------------------------------------------------------------

    /**
     * AES-256-GCM with the plaintext header as additional authenticated data.
     *
     * Authenticated, not merely encrypted: a tampered bundle must fail to open
     * rather than open with garbage. Binding the header in as AAD means the
     * plaintext metadata cannot be edited either — nobody can retarget a
     * bundle at a different exam id while leaving the ciphertext intact.
     *
     * @return array{iv: string, tag: string, ciphertext: string} raw bytes
     */
    private function seal(array $payload, string $key, array $header): array
    {
        $plaintext = gzencode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 6);
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->aad($header),
            self::TAG_BYTES
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Failed to encrypt the offline bundle.');
        }

        return ['iv' => $iv, 'tag' => $tag, 'ciphertext' => $ciphertext];
    }

    /**
     * Open a sealed bundle. The relay's job, implemented here so the server's
     * own tests can assert what a lab machine will actually be able to read —
     * a test that inspects the payload before encryption proves nothing about
     * what shipped.
     *
     * @throws \RuntimeException on a wrong key or a tampered bundle
     */
    public function open(array $envelope, string $base64Key): array
    {
        $cipher = $envelope['cipher'] ?? [];

        $plaintext = openssl_decrypt(
            base64_decode($cipher['ciphertext'] ?? ''),
            self::CIPHER,
            base64_decode($base64Key),
            OPENSSL_RAW_DATA,
            base64_decode($cipher['iv'] ?? ''),
            base64_decode($cipher['tag'] ?? ''),
            $this->aad($envelope['header'] ?? [])
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Bundle failed authentication. It is the wrong key, or the file has been altered.');
        }

        return json_decode(gzdecode($plaintext), true);
    }

    private function aad(array $header): string
    {
        return json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // Key release and revocation
    // ------------------------------------------------------------------

    /**
     * Hand over the content key.
     *
     * A time-lock alone is not the gate — a lab PC's clock can be changed with
     * a mouse. Key release is the gate, and it happens here, on the server,
     * where the clock is ours.
     *
     * @throws \RuntimeException before the exam opens, or after revocation
     */
    public function releaseKey(CbtOfflineBundle $bundle, User $requester): string
    {
        if ($bundle->isRevoked()) {
            throw new \RuntimeException('This bundle has been revoked and its key will not be released.');
        }

        $exam = CbtExam::withoutGlobalScopes()->findOrFail($bundle->exam_id);

        if ($exam->opens_at && now()->lessThan($exam->opens_at)) {
            throw new \RuntimeException(sprintf(
                'This paper opens at %s. The key is released then, not before.',
                $exam->opens_at->toDayDateTimeString()
            ));
        }

        if ($exam->status === 'draft') {
            throw new \RuntimeException('This exam is no longer published.');
        }

        // First unlock is the interesting one; later ones are a relay that
        // restarted and needs the key back into memory (§14).
        $bundle->forceFill([
            'unlocked_at' => $bundle->unlocked_at ?? now(),
            'unlocked_by' => $requester->id,
        ])->save();

        return $bundle->content_key;
    }

    /**
     * Cancel an issuance and reclaim what it reserved.
     *
     * Attempts that were pre-issued and never sat are deleted outright rather
     * than left as debris: a `provisioned` row that nobody reclaims is a paper
     * a candidate can never be issued again, because the next issuance would
     * adopt it.
     *
     * @return int attempts reclaimed
     */
    public function revoke(CbtOfflineBundle $bundle): int
    {
        return DB::transaction(function () use ($bundle) {
            $reclaimed = CbtAttempt::withoutGlobalScopes()
                ->where('offline_bundle_id', $bundle->id)
                ->where('status', CbtAttempt::STATUS_PROVISIONED)
                ->delete();

            $bundle->forceFill(['revoked_at' => now()])->save();

            return $reclaimed;
        });
    }
}
