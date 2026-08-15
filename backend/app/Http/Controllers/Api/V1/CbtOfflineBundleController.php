<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CbtAttempt;
use App\Models\CbtAttemptEvent;
use App\Models\CbtExam;
use App\Models\CbtOfflineBundle;
use App\Services\CbtExamService;
use App\Services\CbtMediaService;
use App\Services\CbtOfflineBundleService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The lab relay's side of the API (docs/offline-cbt-client.md §9.1–§9.3).
 *
 * Three moments, and only the first two need connectivity before the paper
 * starts: provision the encrypted bundle the day before, release its key on
 * exam morning, and upload everything afterwards. Nothing here is reachable
 * during the exam, which is the point.
 *
 * The relay authenticates as staff, not as a candidate. That is the whole
 * reason this controller exists rather than more methods on `CbtController`:
 * the existing `/cbt/offline-sync` route is `role:student` and single-attempt,
 * so a relay uploading on behalf of four hundred candidates is rejected
 * outright by it.
 */
class CbtOfflineBundleController extends Controller
{
    public function __construct(
        private CbtOfflineBundleService $bundles,
        private CbtExamService $exams,
        private CbtMediaService $media
    ) {
    }

    private function schoolId(Request $request): ?int
    {
        $profile = $request->user()?->userProfile;

        if ($profile && $profile->school_id) {
            return (int) $profile->school_id;
        }

        $tenant = $request->attributes->get('tenant_school');

        return $tenant ? (int) $tenant->id : null;
    }

    // ==================================================================
    // §9.1 Bundle issuance
    // ==================================================================

    /**
     * Build the encrypted paper a relay carries into the lab.
     *
     * The response is ciphertext plus a plaintext header of non-sensitive
     * metadata. The key is not in it — that is a separate, audited call on
     * exam morning, and the separation is what lets the bundle sit on a lab
     * laptop overnight.
     */
    public function store(Request $request, $examId)
    {
        $exam = CbtExam::where('school_id', $this->schoolId($request))->findOrFail($examId);
        $this->authorize('view', $exam);

        $validated = $request->validate([
            'relay_identity' => 'nullable|string|max:120',
            'override_existing' => 'nullable|boolean',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'integer',
        ]);

        try {
            $built = $this->bundles->build($exam, $request->user(), [
                'relay_identity' => $validated['relay_identity'] ?? null,
                'override_existing' => (bool) ($validated['override_existing'] ?? false),
                'student_ids' => $validated['student_ids'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $bundle = $built['bundle'];

        // Issuing a paper is a security-relevant event: it is the moment
        // question text leaves the server for a machine we do not control.
        AuditLog::create([
            'school_id' => $exam->school_id,
            'user_id' => $request->user()->id,
            'action' => 'cbt.offline_bundle.issued',
            'auditable_type' => CbtOfflineBundle::class,
            'auditable_id' => $bundle->id,
            'new_values' => [
                'exam_id' => (int) $exam->id,
                'bundle_id' => $bundle->bundle_id,
                'attempt_count' => $bundle->attempt_count,
                'question_count' => $bundle->question_count,
                'relay_identity' => $bundle->relay_identity,
                'override_existing' => (bool) ($validated['override_existing'] ?? false),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Bundle issued. The key is released separately, once the exam opens.',
            'bundle' => $built['envelope'],
            // The relay fetches these files separately and verifies each
            // checksum. Media is not encrypted: it is already reachable to any
            // authenticated candidate before `opens_at` by design, so
            // encrypting it protects nothing and costs the relay its cache.
            'media_manifest' => $this->media->buildOfflineManifest(
                $this->exams->questionsForExam($exam)
            ),
        ], 201);
    }

    /** What has been issued for this exam, and what state it is in. */
    public function index(Request $request, $examId)
    {
        $exam = CbtExam::where('school_id', $this->schoolId($request))->findOrFail($examId);
        $this->authorize('view', $exam);

        $bundles = CbtOfflineBundle::where('exam_id', $exam->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (CbtOfflineBundle $bundle) => [
                'bundle_id' => $bundle->bundle_id,
                'key_id' => $bundle->key_id,
                'format_version' => $bundle->format_version,
                'relay_identity' => $bundle->relay_identity,
                'attempt_count' => $bundle->attempt_count,
                'question_count' => $bundle->question_count,
                'ciphertext_bytes' => $bundle->ciphertext_bytes,
                'built_at' => $bundle->built_at?->toIso8601String(),
                'unlocked_at' => $bundle->unlocked_at?->toIso8601String(),
                'revoked_at' => $bundle->revoked_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $bundles]);
    }

    // ==================================================================
    // §9.2 Key release
    // ==================================================================

    /**
     * Release the content key. Ten seconds of connectivity on exam morning.
     *
     * Refused before `opens_at`. A time-lock on the relay would not be enough
     * on its own — a lab PC's clock can be changed with a mouse — so the gate
     * is here, where the clock is ours.
     */
    public function releaseKey(Request $request, $bundleId)
    {
        $bundle = CbtOfflineBundle::where('school_id', $this->schoolId($request))
            ->where('bundle_id', $bundleId)
            ->firstOrFail();

        $exam = CbtExam::withoutGlobalScopes()->findOrFail($bundle->exam_id);
        $this->authorize('view', $exam);

        try {
            $key = $this->bundles->releaseKey($bundle, $request->user());
        } catch (\RuntimeException $e) {
            $this->auditUnlock($request, $bundle, false, $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 403);
        }

        $this->auditUnlock($request, $bundle, true, null);

        return response()->json([
            'key_id' => $bundle->key_id,
            'algorithm' => 'aes-256-gcm',
            'key' => $key,
            // Said plainly because the relay is expected to honour it, and
            // because an invigilator reading this response should understand
            // what they are now holding.
            'handling' => 'Hold in memory only. Never write this key to disk, a log or a crash dump. Zero it when the exam closes.',
        ]);
    }

    private function auditUnlock(Request $request, CbtOfflineBundle $bundle, bool $granted, ?string $reason): void
    {
        AuditLog::create([
            'school_id' => $bundle->school_id,
            'user_id' => $request->user()->id,
            'action' => $granted ? 'cbt.offline_bundle.key_released' : 'cbt.offline_bundle.key_refused',
            'auditable_type' => CbtOfflineBundle::class,
            'auditable_id' => $bundle->id,
            'new_values' => array_filter([
                'bundle_id' => $bundle->bundle_id,
                'exam_id' => (int) $bundle->exam_id,
                'reason' => $reason,
            ]),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Cancel an issuance.
     *
     * Reclaims the pre-issued attempts nobody sat, so the candidates on the
     * roster can be issued a paper again. Attempts that were actually sat are
     * left alone — they are somebody's exam.
     */
    public function revoke(Request $request, $bundleId)
    {
        $bundle = CbtOfflineBundle::where('school_id', $this->schoolId($request))
            ->where('bundle_id', $bundleId)
            ->firstOrFail();

        $exam = CbtExam::withoutGlobalScopes()->findOrFail($bundle->exam_id);
        $this->authorize('manage', $exam);

        $reclaimed = $this->bundles->revoke($bundle);

        AuditLog::create([
            'school_id' => $bundle->school_id,
            'user_id' => $request->user()->id,
            'action' => 'cbt.offline_bundle.revoked',
            'auditable_type' => CbtOfflineBundle::class,
            'auditable_id' => $bundle->id,
            'new_values' => ['bundle_id' => $bundle->bundle_id, 'attempts_reclaimed' => $reclaimed],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Bundle revoked. Its key will no longer be released.',
            'attempts_reclaimed' => $reclaimed,
        ]);
    }

    // ==================================================================
    // §9.3 Batch sync
    // ==================================================================

    /**
     * Upload a whole room's worth of answers, events and submissions.
     *
     * Three properties matter more than anything else here:
     *
     * **Every attempt is authorised against the bundle's roster.** A relay
     * holds a staff token; without this check that token plus a guessed
     * attempt id would be a write path into any child's paper in the school.
     *
     * **Conflict resolution is not reimplemented.** Each attempt goes through
     * the same `recordAnswers` the live client uses, so an offline batch
     * cannot take a shortcut the online path forbids, and `supersedes()` stays
     * the single definition of which answer wins.
     *
     * **Results are per attempt.** A partial failure must be legible — "398
     * fine, 2 rejected, here is why" — rather than collapsing four hundred
     * candidates into one 422.
     */
    public function batchSync(Request $request)
    {
        $validated = $request->validate([
            'bundle_id' => 'required|uuid',
            'attempts' => 'required|array|min:1|max:500',
            'attempts.*.attempt_id' => 'required|integer',
            'attempts.*.answers' => 'nullable|array',
            'attempts.*.answers.*.question_id' => 'required|integer',
            'attempts.*.answers.*.response' => 'nullable',
            'attempts.*.answers.*.client_timestamp' => 'nullable|date',
            'attempts.*.answers.*.client_sequence' => 'nullable|integer|min:0',
            'attempts.*.answers.*.time_spent_seconds' => 'nullable|integer|min:0',
            'attempts.*.answers.*.flagged_for_review' => 'nullable|boolean',
            'attempts.*.events' => 'nullable|array',
            'attempts.*.events.*.event_type' => ['required', Rule::in(CbtAttemptEvent::RELAY_REPORTABLE_TYPES)],
            'attempts.*.events.*.occurred_at' => 'nullable|date',
            'attempts.*.events.*.metadata' => 'nullable|array',
            'attempts.*.submit' => 'nullable|boolean',
        ]);

        $bundle = CbtOfflineBundle::where('school_id', $this->schoolId($request))
            ->where('bundle_id', $validated['bundle_id'])
            ->firstOrFail();

        $exam = CbtExam::withoutGlobalScopes()->findOrFail($bundle->exam_id);
        $this->authorize('view', $exam);

        $results = [];

        foreach ($validated['attempts'] as $row) {
            $results[] = $this->syncOneAttempt($bundle, $exam, $row);
        }

        return response()->json([
            'bundle_id' => $bundle->bundle_id,
            // The sentence an anxious exam officer needs, in numbers a screen
            // can show: saved, ignored, rejected, and how many are closed out.
            'summary' => [
                'attempts' => count($results),
                'saved' => array_sum(array_column($results, 'saved')),
                'ignored' => array_sum(array_column($results, 'ignored')),
                'rejected' => count(array_filter($results, fn ($r) => $r['rejected'] !== [])),
                'finalised' => count(array_filter($results, fn ($r) => in_array($r['attempt_status'], ['submitted', 'graded'], true))),
                'events' => array_sum(array_column($results, 'events')),
            ],
            'attempts' => $results,
        ]);
    }

    /**
     * One candidate's upload. Never throws: a single bad attempt must not cost
     * the other 399 their sync.
     */
    private function syncOneAttempt(CbtOfflineBundle $bundle, CbtExam $exam, array $row): array
    {
        $attemptId = (int) $row['attempt_id'];

        $result = [
            'attempt_id' => $attemptId,
            'saved' => 0,
            'ignored' => 0,
            'events' => 0,
            'rejected' => [],
            'attempt_status' => null,
        ];

        if (! $bundle->covers($attemptId)) {
            $result['rejected'][] = 'This attempt was not on the roster this bundle was issued for.';

            return $result;
        }

        $attempt = CbtAttempt::withoutGlobalScopes()->find($attemptId);

        if (! $attempt || (int) $attempt->exam_id !== (int) $exam->id) {
            $result['rejected'][] = 'Attempt not found for this exam.';

            return $result;
        }

        // A candidate the relay actually seated has a `provisioned` row
        // waiting. Promote it before recording, or `recordAnswers` would be
        // writing to a paper the server still believes nobody started.
        if ($attempt->isProvisioned()) {
            $attempt = $this->exams->activateProvisionedAttempt($attempt, $exam, $attempt->started_at);
        }

        $result['events'] = $this->recordEvents($attempt, $row['events'] ?? []);

        $answers = $row['answers'] ?? [];

        if ($answers !== []) {
            $recorded = $this->exams->recordAnswers($attempt, $answers, fromOfflineClient: true);
            $result['saved'] = $recorded['saved'];
            $result['ignored'] = $recorded['ignored'];
            $result['rejected'] = array_merge($result['rejected'], $recorded['rejected']);
        }

        // A batch that arrives after the deadline is still saved, then closed
        // out — the paper was sat on time even if the internet came back late.
        if (($row['submit'] ?? false) || $attempt->hasExpired()) {
            $attempt = $this->exams->submitAttempt(
                $attempt,
                ($row['submit'] ?? false) ? 'manual_submit' : 'auto_submit'
            );
        }

        $result['attempt_status'] = $attempt->fresh()->status;

        return $result;
    }

    /**
     * Integrity events ride along with the attempt they belong to (§9.7).
     * They have no endpoint of their own because they are worthless without
     * it — "focus lost at 10:42" means nothing detached from a candidate.
     */
    private function recordEvents(CbtAttempt $attempt, array $events): int
    {
        if ($events === []) {
            return 0;
        }

        $flags = $attempt->integrity_flags ?? [];
        $written = 0;

        foreach ($events as $event) {
            // The relay's own timestamp, kept as observed. These are evidence
            // for a human reading a malpractice question later, so rewriting
            // them to sync time would destroy the sequence that makes them
            // mean anything.
            $occurredAt = isset($event['occurred_at'])
                ? \Illuminate\Support\Carbon::parse($event['occurred_at'])
                : now();

            // Retrying an interrupted sync is expected and must not inflate a
            // candidate's focus-loss count — a duplicated integrity trail is
            // worse than a missing one, because somebody will act on it.
            $already = CbtAttemptEvent::where('attempt_id', $attempt->id)
                ->where('event_type', $event['event_type'])
                ->where('occurred_at', $occurredAt)
                ->exists();

            if ($already) {
                continue;
            }

            CbtAttemptEvent::create([
                'attempt_id' => $attempt->id,
                'event_type' => $event['event_type'],
                'occurred_at' => $occurredAt,
                'metadata' => $event['metadata'] ?? null,
            ]);

            $flags[$event['event_type']] = ($flags[$event['event_type']] ?? 0) + 1;
            $written++;
        }

        $attempt->update(['integrity_flags' => $flags]);

        return $written;
    }
}
