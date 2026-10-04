<?php

namespace App\Services\Results;

use App\Models\AuditLog;
use App\Models\Broadsheet;
use App\Models\BroadsheetCorrection;
use App\Models\BroadsheetEvent;
use App\Models\BroadsheetOverride;
use App\Models\BroadsheetSubjectSubmission;
use App\Models\BroadsheetVersion;
use App\Models\CaScheme;
use App\Models\ResultRelease;
use App\Models\SchoolClass;
use App\Models\SchoolSectionSetting;
use App\Models\ScoreEntry;
use App\Models\User;
use App\Services\GradingService;
use App\Services\ScoreInput;
use App\Services\Sections\HeadResolver;
use App\Support\Sections;
use Illuminate\Support\Facades\DB;
use App\Exceptions\DomainRefusal;

/**
 * A class's results moving through review (decision D2, broadsheet spec §9).
 *
 * The rules, in one place:
 *  - Step 0 is always the subject teachers handing in their subjects. Each
 *    later step locks what the step before it submitted.
 *  - Every step is a role, resolved per class by HeadResolver.
 *  - A return reopens only what was returned, back at step 0, with a reason.
 *  - Errors block every approval. Warnings block the final one until someone
 *    with authority accepts each, with a reason.
 *  - Final approval freezes a hashed snapshot. A later change is a correction:
 *    it waits for approval, then produces a new version and keeps the old one.
 *
 * Failures are DomainRefusal with a sentence fit to show the user; the
 * controller turns them into 409/403 responses.
 */
class BroadsheetWorkflow
{
    public function __construct(
        private ResultCalculator $calculator,
        private BroadsheetValidator $validator,
        private HeadResolver $heads,
        private GradingService $grading,
    ) {
    }

    /** The broadsheet for a class and term, created on first touch. */
    public function open(int $schoolId, int $termId, int $classId): Broadsheet
    {
        $class = SchoolClass::withoutGlobalScopes()->where('school_id', $schoolId)->findOrFail($classId);
        $levelGroup = Sections::levelGroupOf($class->level_category);
        $steps = $this->stepsFor($schoolId, $levelGroup);

        $broadsheet = Broadsheet::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('term_id', $termId)
            ->where('class_id', $classId)
            ->first();

        if (! $broadsheet) {
            return DB::transaction(function () use ($schoolId, $termId, $classId, $levelGroup, $steps) {
                $broadsheet = Broadsheet::create([
                    'school_id' => $schoolId,
                    'term_id' => $termId,
                    'class_id' => $classId,
                    'reference' => 'BS-'.now()->format('Y').'-'.strtoupper(bin2hex(random_bytes(4))),
                    'level_group' => $levelGroup,
                    'status' => 'draft',
                    'steps' => $steps,
                    'current_step' => 0,
                ]);

                $broadsheet->update(['reference' => sprintf('BS-%s-%06d', now()->format('Y'), $broadsheet->id)]);

                return $broadsheet;
            });
        }

        // A chain edited before anything moved applies; after that, the
        // broadsheet keeps the chain it started under.
        if ($broadsheet->status === 'draft' && $broadsheet->current_version === 0
            && ! $broadsheet->submissions()->where('status', '!=', 'draft')->exists()
            && $broadsheet->steps !== $steps) {
            $broadsheet->update(['steps' => $steps]);
        }

        return $broadsheet;
    }

    /** @return array<int, array{role: string, label: string}> */
    public function stepsFor(int $schoolId, string $levelGroup): array
    {
        $settings = SchoolSectionSetting::for($schoolId);

        return Sections::enabledSteps($settings->approval_chains, Sections::sectionOf($levelGroup));
    }

    /**
     * Why a mark cannot be edited right now, or null when it can.
     *
     * Only broadsheets that exist constrain editing: a school that never
     * opened one keeps entering marks exactly as before.
     */
    public function editBlockReason(int $schoolId, int $termId, int $classId, int $studentId, int $subjectId): ?string
    {
        $broadsheet = Broadsheet::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('term_id', $termId)
            ->where('class_id', $classId)
            ->first();

        if (! $broadsheet) {
            return null;
        }

        if ($broadsheet->isLocked()) {
            return "This class's results are {$broadsheet->status}. Request a correction instead; it needs approval.";
        }

        $submission = $broadsheet->submissions()->where('subject_id', $subjectId)->first();

        if ($submission && ! $submission->allowsEditFor($studentId)) {
            return $submission->status === 'returned'
                ? 'Only the students returned for correction can be edited in this subject.'
                : 'This subject has been submitted for review and is locked.';
        }

        return null;
    }

    public function calculate(Broadsheet $broadsheet): array
    {
        return $this->calculator->forClass($broadsheet->school_id, $broadsheet->term_id, $broadsheet->class_id);
    }

    public function validation(Broadsheet $broadsheet, ?array $calc = null): array
    {
        return $this->validator->validate($calc ?? $this->calculate($broadsheet), $broadsheet);
    }

    // ------------------------------------------------------------------
    // Step 0: subject teachers
    // ------------------------------------------------------------------

    public function submitSubject(Broadsheet $broadsheet, int $subjectId, User $user): BroadsheetSubjectSubmission
    {
        if (! in_array($broadsheet->status, ['draft', 'returned', 'rejected'], true)) {
            throw new DomainRefusal('This broadsheet is already under review; subjects can only be handed in before review starts or after a return.');
        }

        $this->authorise($broadsheet, 'subject_teacher', $user, $subjectId);

        return DB::transaction(function () use ($broadsheet, $subjectId, $user) {
            $submission = BroadsheetSubjectSubmission::withoutGlobalScopes()->firstOrNew([
                'broadsheet_id' => $broadsheet->id,
                'subject_id' => $subjectId,
            ], ['school_id' => $broadsheet->school_id]);

            $submission->fill([
                'status' => 'submitted',
                'returned_student_ids' => null,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ])->save();

            $this->log($broadsheet, 'submit_subject', $user, null, ['subject_id' => $subjectId], 0);

            $this->advanceIfAllSubmitted($broadsheet->fresh());

            return $submission;
        });
    }

    private function advanceIfAllSubmitted(Broadsheet $broadsheet): void
    {
        $calc = $this->calculator->forClass($broadsheet->school_id, $broadsheet->term_id, $broadsheet->class_id, ['attendance' => false]);
        $subjectIds = array_column($calc['subjects'], 'id');

        if ($subjectIds === []) {
            return;
        }

        $submitted = $broadsheet->submissions()->where('status', 'submitted')->pluck('subject_id')->map(fn ($id) => (int) $id)->all();

        if (array_diff($subjectIds, $submitted) !== []) {
            return;
        }

        $broadsheet->update(['status' => 'in_review', 'current_step' => 0]);
        $this->moveToNextStep($broadsheet, null);
    }

    // ------------------------------------------------------------------
    // Review steps
    // ------------------------------------------------------------------

    /**
     * Approve the current step (for the class teacher, this is "compile").
     *
     * @return array{broadsheet: Broadsheet, version: ?BroadsheetVersion}
     */
    public function approve(Broadsheet $broadsheet, User $user, ?string $note = null): array
    {
        if ($broadsheet->status !== 'in_review') {
            throw new DomainRefusal('Only a broadsheet under review can be approved.');
        }

        $role = $broadsheet->currentStepRole();
        $calc = $this->calculate($broadsheet);
        $validation = $this->validator->validate($calc, $broadsheet);

        if ($validation['errors'] > 0) {
            throw new DomainRefusal("{$validation['errors']} error(s) must be fixed before this step can be approved.");
        }

        $isFinal = $broadsheet->current_step === count($broadsheet->steps) - 1;

        if ($isFinal && $validation['unresolved_warnings'] > 0) {
            throw new DomainRefusal("{$validation['unresolved_warnings']} warning(s) need a recorded override before final approval.");
        }

        return DB::transaction(function () use ($broadsheet, $user, $note, $role, $calc, $validation, $isFinal) {
            if ($role === 'hod') {
                $departments = $this->hodDepartmentsFor($broadsheet, $calc, $user);

                if ($departments === []) {
                    throw new DomainRefusal('You are not the head of any department with a subject on this broadsheet.');
                }

                foreach ($departments as $department) {
                    $this->log($broadsheet, 'approve', $user, $note, null, $broadsheet->current_step, $department);
                }

                if ($this->pendingHodDepartments($broadsheet, $calc) !== []) {
                    return ['broadsheet' => $broadsheet->fresh(), 'version' => null];
                }
            } else {
                $this->authorise($broadsheet, $role, $user);
                $this->log($broadsheet, $role === 'class_teacher' ? 'compile' : 'approve', $user, $note, null, $broadsheet->current_step);
            }

            if (! $isFinal) {
                $this->moveToNextStep($broadsheet, $calc);

                return ['broadsheet' => $broadsheet->fresh(), 'version' => null];
            }

            $version = $this->freeze($broadsheet, $calc, $validation, $user);
            $releaseOnApproval = $role === 'principal';

            $broadsheet->update([
                'status' => $releaseOnApproval ? 'published' : 'approved',
                'published_at' => $releaseOnApproval ? now() : null,
            ]);

            if ($releaseOnApproval) {
                $this->release($broadsheet, $user);
            }

            $this->grading->recomputeClassPositions($broadsheet->school_id, $broadsheet->class_id, $broadsheet->term_id);

            return ['broadsheet' => $broadsheet->fresh(), 'version' => $version];
        });
    }

    /**
     * Send items back for correction. Only the named subjects (and, within
     * them, only the named students) reopen.
     *
     * @param  array<int, array{subject_id: int, student_ids?: array<int, int>|null}>  $items
     */
    public function returnItems(Broadsheet $broadsheet, User $user, array $items, string $reason): Broadsheet
    {
        if ($broadsheet->status !== 'in_review') {
            throw new DomainRefusal('Only a broadsheet under review can be returned. Approved results change through a correction.');
        }

        $this->authoriseReviewer($broadsheet, $user);

        return DB::transaction(function () use ($broadsheet, $user, $items, $reason) {
            foreach ($items as $item) {
                $submission = BroadsheetSubjectSubmission::withoutGlobalScopes()
                    ->where('broadsheet_id', $broadsheet->id)
                    ->where('subject_id', $item['subject_id'])
                    ->first();

                if (! $submission) {
                    throw new DomainRefusal('A returned subject is not on this broadsheet.');
                }

                $submission->update([
                    'status' => 'returned',
                    'returned_student_ids' => isset($item['student_ids']) && $item['student_ids'] !== [] ? array_values(array_map('intval', $item['student_ids'])) : null,
                ]);
            }

            $this->log($broadsheet, 'return', $user, $reason, ['items' => $items], $broadsheet->current_step);
            $broadsheet->update(['status' => 'returned', 'current_step' => 0]);

            return $broadsheet->fresh();
        });
    }

    public function reject(Broadsheet $broadsheet, User $user, string $reason): Broadsheet
    {
        if ($broadsheet->status !== 'in_review') {
            throw new DomainRefusal('Only a broadsheet under review can be rejected.');
        }

        $this->authoriseReviewer($broadsheet, $user);

        return DB::transaction(function () use ($broadsheet, $user, $reason) {
            BroadsheetSubjectSubmission::withoutGlobalScopes()
                ->where('broadsheet_id', $broadsheet->id)
                ->update(['status' => 'draft', 'returned_student_ids' => null]);

            $this->log($broadsheet, 'reject', $user, $reason, null, $broadsheet->current_step);
            $broadsheet->update(['status' => 'rejected', 'current_step' => 0]);

            return $broadsheet->fresh();
        });
    }

    /** Accept a warning on the record. Errors cannot be overridden. */
    public function override(Broadsheet $broadsheet, User $user, string $code, string $key, string $reason): BroadsheetOverride
    {
        $this->authoriseReviewer($broadsheet, $user);

        $validation = $this->validation($broadsheet);
        $issue = collect($validation['issues'])->first(fn ($i) => $i['code'] === $code && $i['key'] === $key);

        if (! $issue) {
            throw new DomainRefusal('That issue is not on this broadsheet any more.');
        }

        if ($issue['severity'] !== 'warning') {
            throw new DomainRefusal('Only warnings can be overridden. Errors have to be fixed.');
        }

        $override = BroadsheetOverride::withoutGlobalScopes()->updateOrCreate(
            ['broadsheet_id' => $broadsheet->id, 'issue_code' => $code, 'issue_key' => $key],
            ['school_id' => $broadsheet->school_id, 'reason' => $reason, 'user_id' => $user->id]
        );

        $this->log($broadsheet, 'override', $user, $reason, ['code' => $code, 'key' => $key, 'message' => $issue['message']]);

        return $override;
    }

    /** Release approved results to students and parents. */
    public function publish(Broadsheet $broadsheet, User $user): Broadsheet
    {
        if ($broadsheet->status !== 'approved') {
            throw new DomainRefusal('Only approved results can be published.');
        }

        $this->authoriseRelease($broadsheet, $user);

        return DB::transaction(function () use ($broadsheet, $user) {
            $broadsheet->update(['status' => 'published', 'published_at' => now()]);
            $this->release($broadsheet, $user);
            $this->log($broadsheet, 'publish', $user, null, ['version' => $broadsheet->current_version]);

            return $broadsheet->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Corrections to approved results
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $newValues  `components` per scheme part and/or `result_state`
     */
    public function requestCorrection(Broadsheet $broadsheet, User $user, int $studentId, int $subjectId, array $newValues, string $reason): BroadsheetCorrection
    {
        if (! $broadsheet->isLocked()) {
            throw new DomainRefusal('Results that are not yet approved are edited directly, not corrected.');
        }

        $allowed = $this->heads->canAct($user, $broadsheet->school_id, 'subject_teacher', $broadsheet->class_id, $broadsheet->level_group, $subjectId)
            || $this->heads->canAct($user, $broadsheet->school_id, 'class_teacher', $broadsheet->class_id, $broadsheet->level_group)
            || $this->canDecideCorrections($broadsheet, $user);

        if (! $allowed) {
            throw new AuthorisationFailure('You cannot request corrections for this subject.');
        }

        $calc = $this->calculator->forClass($broadsheet->school_id, $broadsheet->term_id, $broadsheet->class_id, ['attendance' => false]);
        $student = collect($calc['students'])->firstWhere('id', $studentId);

        if (! $student || ! isset($student['results'][$subjectId])) {
            throw new DomainRefusal('That student and subject are not on this broadsheet.');
        }

        $current = $student['results'][$subjectId];

        return DB::transaction(function () use ($broadsheet, $user, $studentId, $subjectId, $newValues, $reason, $current) {
            $correction = BroadsheetCorrection::create([
                'school_id' => $broadsheet->school_id,
                'broadsheet_id' => $broadsheet->id,
                'student_id' => $studentId,
                'subject_id' => $subjectId,
                'score_entry_id' => $current['score_entry_id'],
                'old_values' => ['components' => $current['components'], 'total' => $current['total'], 'grade' => $current['grade'], 'state' => $current['state']],
                'new_values' => $newValues,
                'reason' => $reason,
                'status' => 'pending',
                'requested_by' => $user->id,
            ]);

            $this->log($broadsheet, 'correction_requested', $user, $reason, ['correction_id' => $correction->id, 'student_id' => $studentId, 'subject_id' => $subjectId]);

            return $correction;
        });
    }

    public function decideCorrection(BroadsheetCorrection $correction, User $user, bool $approve, ?string $note): BroadsheetCorrection
    {
        $broadsheet = Broadsheet::withoutGlobalScopes()->findOrFail($correction->broadsheet_id);

        if ($correction->status !== 'pending') {
            throw new DomainRefusal('This correction has already been decided.');
        }

        if (! $this->canDecideCorrections($broadsheet, $user)) {
            throw new AuthorisationFailure('Only the section head, principal or school admin can decide corrections.');
        }

        if ($correction->requested_by === $user->id && $user->userProfile?->role !== 'school_admin') {
            throw new DomainRefusal('A correction needs approval from someone other than the person who requested it.');
        }

        return DB::transaction(function () use ($correction, $broadsheet, $user, $approve, $note) {
            if (! $approve) {
                $correction->update(['status' => 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note]);
                $this->log($broadsheet, 'correction_rejected', $user, $note, ['correction_id' => $correction->id]);

                return $correction->fresh();
            }

            $this->applyScore($broadsheet, $correction);

            $calc = $this->calculate($broadsheet);
            $validation = $this->validator->validate($calc, $broadsheet);

            if ($validation['errors'] > 0) {
                throw new DomainRefusal('The corrected marks would leave the broadsheet with errors: '.collect($validation['issues'])->where('severity', 'error')->pluck('message')->first());
            }

            BroadsheetVersion::withoutGlobalScopes()
                ->where('broadsheet_id', $broadsheet->id)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now(), 'superseded_reason' => "Correction #{$correction->id}: {$correction->reason}"]);

            $version = $this->freeze($broadsheet, $calc, $validation, $user);

            $correction->update([
                'status' => 'applied',
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'applied_in_version' => $version->version,
            ]);

            $broadsheet->update(['report_cards_stale' => true]);
            $this->grading->recomputeClassPositions($broadsheet->school_id, $broadsheet->class_id, $broadsheet->term_id);

            // Progress reports already downloaded no longer match (PRD §15.4).
            \App\Models\ProgressReportExport::withoutGlobalScopes()
                ->where('school_id', $broadsheet->school_id)
                ->where('student_id', $correction->student_id)
                ->update(['outdated' => true]);

            // A closed term's locked record follows, as a new version.
            app(\App\Services\History\AcademicRecordService::class)
                ->applyBroadsheetCorrection($broadsheet->fresh(), (int) $correction->student_id, $correction->reason, $user);
            $this->log($broadsheet, 'correction_applied', $user, $note, ['correction_id' => $correction->id, 'version' => $version->version]);

            return $correction->fresh();
        });
    }

    private function applyScore(Broadsheet $broadsheet, BroadsheetCorrection $correction): void
    {
        $class = SchoolClass::withoutGlobalScopes()->find($broadsheet->class_id);
        $scheme = CaScheme::forTerm($broadsheet->school_id, $broadsheet->term_id, $broadsheet->level_group);
        $new = $correction->new_values;

        $entry = ScoreEntry::withoutGlobalScopes()->firstOrNew([
            'school_id' => $broadsheet->school_id,
            'term_id' => $broadsheet->term_id,
            'student_id' => $correction->student_id,
            'subject_id' => $correction->subject_id,
        ]);

        $state = $new['result_state'] ?? $entry->result_state;

        if (isset($new['components']) && is_array($new['components'])) {
            $marks = ScoreInput::resolve($scheme, ['components' => $new['components']], $entry->component_scores);
            $computed = $this->grading->total($scheme, $marks['first_ca'], $marks['second_ca'], $marks['exam'], $class?->level_category);

            $entry->fill([
                'first_ca' => $marks['first_ca'],
                'second_ca' => $marks['second_ca'],
                'exam' => $marks['exam'],
                'component_scores' => $marks['component_scores'],
                'total_score' => $computed['percentage'],
                'grade' => $computed['grade'],
            ]);
        }

        $entry->result_state = in_array($state, ['absent', 'exempt'], true) ? $state : null;
        $entry->save();

        $correction->update(['score_entry_id' => $entry->id]);

        AuditLog::create([
            'school_id' => $broadsheet->school_id,
            'user_id' => auth()->id(),
            'action' => 'result.corrected',
            'auditable_type' => ScoreEntry::class,
            'auditable_id' => $entry->id,
            'old_values' => $correction->old_values,
            'new_values' => $correction->new_values + ['reason' => $correction->reason],
        ]);
    }

    // ------------------------------------------------------------------
    // Permission helpers
    // ------------------------------------------------------------------

    public function canDecideCorrections(Broadsheet $broadsheet, User $user): bool
    {
        if ($user->userProfile?->role === 'school_admin') {
            return true;
        }

        foreach (['level_head', 'principal'] as $role) {
            $holders = $this->heads->holders($broadsheet->school_id, $role, $broadsheet->class_id, $broadsheet->level_group);

            if (in_array($user->id, $holders, true)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a user may act at the broadsheet's current step. */
    public function canActNow(Broadsheet $broadsheet, User $user): bool
    {
        $role = $broadsheet->currentStepRole();

        if ($broadsheet->status !== 'in_review' || $role === null || $role === 'subject_teacher') {
            return false;
        }

        if ($role === 'hod') {
            return $this->hodDepartmentsFor($broadsheet, $this->calculate($broadsheet), $user) !== [];
        }

        return $this->heads->canAct($user, $broadsheet->school_id, $role, $broadsheet->class_id, $broadsheet->level_group);
    }

    private function authorise(Broadsheet $broadsheet, string $role, User $user, ?int $subjectId = null): void
    {
        if (! $this->heads->canAct($user, $broadsheet->school_id, $role, $broadsheet->class_id, $broadsheet->level_group, $subjectId)) {
            throw new AuthorisationFailure('This step belongs to the '.strtolower(Sections::STEP_ROLES[$role] ?? $role).' for this class.');
        }
    }

    /**
     * Returning, rejecting and overriding belong to the reviewer at the
     * current step, and to the section head, principal and school admin, who
     * can always send work back but never approve a step that is not theirs.
     */
    private function authoriseReviewer(Broadsheet $broadsheet, User $user): void
    {
        if (! $this->canActNow($broadsheet, $user) && ! $this->canDecideCorrections($broadsheet, $user)) {
            throw new AuthorisationFailure('Only the reviewer at the current step can do this.');
        }
    }

    private function authoriseRelease(Broadsheet $broadsheet, User $user): void
    {
        if (! $this->canDecideCorrections($broadsheet, $user)) {
            throw new AuthorisationFailure('Only the section head, principal or school admin can publish results.');
        }
    }

    // ------------------------------------------------------------------
    // HOD step
    // ------------------------------------------------------------------

    /** Departments on this broadsheet that have a head. @return array<int, string> */
    private function departmentsWithHod(Broadsheet $broadsheet, array $calc): array
    {
        $departments = collect($calc['subjects'])->pluck('department')->filter()->unique()->values()->all();

        return array_values(array_filter($departments, fn ($d) => $this->heads->holders(
            $broadsheet->school_id, 'hod', $broadsheet->class_id, $broadsheet->level_group, null, $d
        ) !== []));
    }

    private function hodDepartmentsFor(Broadsheet $broadsheet, array $calc, User $user): array
    {
        return array_values(array_filter(
            $this->pendingHodDepartments($broadsheet, $calc),
            fn ($d) => in_array($user->id, $this->heads->holders($broadsheet->school_id, 'hod', $broadsheet->class_id, $broadsheet->level_group, null, $d), true)
        ));
    }

    private function pendingHodDepartments(Broadsheet $broadsheet, array $calc): array
    {
        $since = BroadsheetEvent::withoutGlobalScopes()
            ->where('broadsheet_id', $broadsheet->id)
            ->whereIn('action', ['return', 'reject'])
            ->max('id') ?? 0;

        $approved = BroadsheetEvent::withoutGlobalScopes()
            ->where('broadsheet_id', $broadsheet->id)
            ->where('action', 'approve')
            ->where('step_role', 'hod')
            ->where('id', '>', $since)
            ->pluck('department')
            ->all();

        return array_values(array_diff($this->departmentsWithHod($broadsheet, $calc), $approved));
    }

    /** Move to the next step, skipping an HOD step with no department heads. */
    private function moveToNextStep(Broadsheet $broadsheet, ?array $calc): void
    {
        $next = $broadsheet->current_step + 1;

        while (isset($broadsheet->steps[$next]) && $broadsheet->steps[$next]['role'] === 'hod') {
            $calc ??= $this->calculator->forClass($broadsheet->school_id, $broadsheet->term_id, $broadsheet->class_id, ['attendance' => false]);

            if ($this->departmentsWithHod($broadsheet, $calc) !== []) {
                break;
            }

            $next++;
        }

        $broadsheet->update(['current_step' => min($next, count($broadsheet->steps) - 1)]);
    }

    // ------------------------------------------------------------------
    // Records
    // ------------------------------------------------------------------

    private function freeze(Broadsheet $broadsheet, array $calc, array $validation, User $user): BroadsheetVersion
    {
        $versionNumber = $broadsheet->current_version + 1;

        $snapshot = json_encode([
            'broadsheet' => ['id' => $broadsheet->id, 'reference' => $broadsheet->reference, 'version' => $versionNumber],
            'calculation' => $calc,
            'steps' => $broadsheet->steps,
            'approved_by' => ['id' => $user->id, 'name' => $user->name],
            'approved_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        $version = BroadsheetVersion::create([
            'school_id' => $broadsheet->school_id,
            'broadsheet_id' => $broadsheet->id,
            'version' => $versionNumber,
            'snapshot' => $snapshot,
            'snapshot_hash' => hash('sha256', $snapshot),
            'validation' => ['errors' => $validation['errors'], 'warnings' => $validation['warnings'], 'overrides' => collect($validation['issues'])->where('overridden', true)->values()->all()],
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $broadsheet->update(['current_version' => $versionNumber]);

        return $version;
    }

    private function release(Broadsheet $broadsheet, User $user): void
    {
        ResultRelease::withoutGlobalScopes()->updateOrCreate(
            ['school_id' => $broadsheet->school_id, 'term_id' => $broadsheet->term_id, 'class_id' => $broadsheet->class_id],
            ['is_released' => true, 'released_by' => $user->id, 'released_at' => now()]
        );
    }

    public function log(Broadsheet $broadsheet, string $action, ?User $user, ?string $reason = null, ?array $detail = null, ?int $step = null, ?string $department = null): BroadsheetEvent
    {
        return BroadsheetEvent::create([
            'school_id' => $broadsheet->school_id,
            'broadsheet_id' => $broadsheet->id,
            'action' => $action,
            'step' => $step,
            'step_role' => $step !== null ? ($broadsheet->steps[$step]['role'] ?? null) : null,
            'department' => $department,
            'user_id' => $user?->id,
            'reason' => $reason,
            'detail' => $detail,
        ]);
    }
}
