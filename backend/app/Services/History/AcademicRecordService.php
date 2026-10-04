<?php

namespace App\Services\History;

use App\Models\AuditLog;
use App\Models\Broadsheet;
use App\Models\PreviousSchoolRecord;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentTermReport;
use App\Models\Term;
use App\Models\TermClosure;
use App\Models\TermSnapshot;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Results\ResultCalculator;
use Illuminate\Support\Facades\DB;
use App\Exceptions\DomainRefusal;

/**
 * The permanent academic record (history instructions §3–§4).
 *
 * Past terms live in locked snapshots taken at term close, from the approved
 * broadsheet where there is one. A snapshot carries the grading scale and
 * scheme it was issued under, so changing either later never rewrites an old
 * term. A correction is a new version; the earlier one is kept.
 */
class AcademicRecordService
{
    public function __construct(private ResultCalculator $calculator)
    {
    }

    /**
     * Lock a class's term.
     *
     * From the approved broadsheet if one exists. Without one, the live
     * figures are locked only when the caller explicitly accepts that they
     * were never approved — and the snapshot says so.
     *
     * Idempotent: a student who already has a current snapshot for the term
     * is left as they are.
     *
     * @return array{created: int, skipped: int, from_approved_broadsheet: bool}
     */
    public function closeTerm(int $schoolId, int $termId, int $classId, User $user, bool $acceptUnapproved = false): array
    {
        $broadsheet = Broadsheet::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('term_id', $termId)
            ->where('class_id', $classId)
            ->first();

        $version = $broadsheet?->isLocked() ? $broadsheet->latestVersion() : null;

        if (! $version && ! $acceptUnapproved) {
            throw new DomainRefusal('This class has no approved broadsheet for the term. Approve it first, or confirm that you are locking unapproved figures.');
        }

        $calc = $version
            ? $version->data()['calculation']
            : $this->calculator->forClass($schoolId, $termId, $classId);

        return DB::transaction(function () use ($schoolId, $termId, $classId, $user, $calc, $version, $broadsheet) {
            $created = 0;
            $skipped = 0;

            foreach ($calc['students'] as $row) {
                $exists = TermSnapshot::withoutGlobalScopes()
                    ->where('school_id', $schoolId)
                    ->where('student_id', $row['id'])
                    ->where('term_id', $termId)
                    ->whereNull('superseded_at')
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                $this->write($schoolId, $row['id'], $calc, [
                    'version' => 1,
                    'source' => $version ? 'broadsheet_version' : 'live_at_close',
                    'broadsheet_version' => $version?->version,
                    'broadsheet_reference' => $broadsheet?->reference,
                ], $user);

                $created++;
            }

            $closure = TermClosure::withoutGlobalScopes()->updateOrCreate(
                ['school_id' => $schoolId, 'term_id' => $termId, 'class_id' => $classId],
                ['closed_by' => $user->id, 'students' => count($calc['students']), 'from_approved_broadsheet' => $version !== null]
            );

            AuditLog::create([
                'school_id' => $schoolId,
                'user_id' => $user->id,
                'action' => 'term.closed',
                'auditable_type' => TermClosure::class,
                'auditable_id' => $closure->id,
                'new_values' => ['term_id' => $termId, 'class_id' => $classId, 'created' => $created, 'from_approved_broadsheet' => $version !== null],
            ]);

            return ['created' => $created, 'skipped' => $skipped, 'from_approved_broadsheet' => $version !== null];
        });
    }

    /**
     * A broadsheet correction after the term closed: the affected student's
     * snapshot gets a new version from the new approved broadsheet version.
     */
    public function applyBroadsheetCorrection(Broadsheet $broadsheet, int $studentId, string $reason, User $approver): ?TermSnapshot
    {
        $closed = TermClosure::withoutGlobalScopes()
            ->where('school_id', $broadsheet->school_id)
            ->where('term_id', $broadsheet->term_id)
            ->where('class_id', $broadsheet->class_id)
            ->exists();

        if (! $closed) {
            return null;
        }

        $version = $broadsheet->latestVersion();
        $calc = $version->data()['calculation'];

        return $this->correct($broadsheet->school_id, $studentId, $broadsheet->term_id, $calc, $reason, $approver, [
            'source' => 'broadsheet_version',
            'broadsheet_version' => $version->version,
            'broadsheet_reference' => $broadsheet->reference,
        ]);
    }

    /** New version of a student's term snapshot; the old one is superseded, never altered. */
    public function correct(int $schoolId, int $studentId, int $termId, array $calc, string $reason, User $approver, array $meta = []): TermSnapshot
    {
        return DB::transaction(function () use ($schoolId, $studentId, $termId, $calc, $reason, $approver, $meta) {
            $current = TermSnapshot::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('student_id', $studentId)
                ->where('term_id', $termId)
                ->whereNull('superseded_at')
                ->lockForUpdate()
                ->first();

            $current?->update(['superseded_at' => now()]);

            $snapshot = $this->write($schoolId, $studentId, $calc, $meta + [
                'version' => ($current?->version ?? 0) + 1,
                'source' => $meta['source'] ?? 'broadsheet_version',
                'correction_reason' => $reason,
                'correction_approved_by' => $approver->id,
            ], $approver);

            AuditLog::create([
                'school_id' => $schoolId,
                'user_id' => $approver->id,
                'action' => 'term_snapshot.corrected',
                'auditable_type' => TermSnapshot::class,
                'auditable_id' => $snapshot->id,
                'old_values' => $current ? ['version' => $current->version, 'hash' => $current->data_hash] : null,
                'new_values' => ['version' => $snapshot->version, 'hash' => $snapshot->data_hash, 'reason' => $reason],
            ]);

            // A package already sent to another school is now out of date (§8).
            app(\App\Services\History\TransferService::class)->supersedePackagesFor($schoolId, $studentId, "Result corrected: {$reason}");

            return $snapshot;
        });
    }

    /** Build and store one student's snapshot from a calculation. */
    private function write(int $schoolId, int $studentId, array $calc, array $meta, User $user): TermSnapshot
    {
        $row = collect($calc['students'])->firstWhere('id', $studentId);

        if (! $row) {
            throw new DomainRefusal('That student is not on this class\'s results.');
        }

        $report = StudentTermReport::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('student_id', $studentId)
            ->where('term_id', $calc['term']['id'])
            ->first();

        $subjects = [];

        foreach ($calc['subjects'] as $subject) {
            $r = $row['results'][$subject['id']] ?? null;

            if (! $r || $r['state'] === 'not_offered') {
                continue;
            }

            $subjects[] = [
                'subject_id' => $subject['id'],
                'subject' => $subject['name'],
                'state' => $r['state'],
                'components' => $r['components'],
                'total' => $r['total'],
                'grade' => $r['grade'],
                'remark' => $r['remark'],
                'position' => $r['position'],
                'class_average' => $calc['subject_stats'][$subject['id']]['average'] ?? null,
                'teacher_comment' => $r['teacher_comment'],
            ];
        }

        $data = [
            'school' => ['id' => $schoolId, 'name' => School::find($schoolId)?->name],
            'student' => ['id' => $studentId, 'name' => $row['name'], 'admission_number' => $row['admission_number']],
            'session' => $calc['term']['session'],
            'term' => $calc['term']['name'],
            'class' => $calc['class']['name'],
            'level_group' => $calc['class']['level_group'],
            'subjects' => $subjects,
            'total' => $row['total'],
            'average' => $row['average'],
            'grade' => $row['grade'],
            'position' => $row['position'],
            'class_size' => count($calc['students']),
            'class_average' => $calc['class_stats']['class_average'],
            'attendance' => $row['attendance'] ?? null,
            'ratings' => $report?->ratings,
            'form_teacher_comment' => $report?->form_teacher_comment,
            'principal_comment' => $report?->principal_comment,
            'grading_scale' => $calc['grading_bands'],
            'scheme' => $calc['scheme'],
            'pass_mark' => $calc['config']['pass_mark'],
            'ranking' => ['basis' => $calc['config']['ranking_basis'], 'method' => $calc['config']['ranking_method']],
            'partial_term' => $row['partial_term'],
            'broadsheet_reference' => $meta['broadsheet_reference'] ?? null,
            'locked_at' => now()->toIso8601String(),
        ];

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return TermSnapshot::create([
            'school_id' => $schoolId,
            'student_id' => $studentId,
            'term_id' => $calc['term']['id'],
            'session_id' => $calc['term']['session_id'],
            'class_id' => $calc['class']['id'],
            'session_name' => $calc['term']['session'],
            'term_name' => $calc['term']['name'],
            'class_name' => $calc['class']['name'],
            'version' => $meta['version'],
            'source' => $meta['source'],
            'provenance' => 'system_generated',
            'broadsheet_version' => $meta['broadsheet_version'] ?? null,
            'partial_term' => (bool) $row['partial_term'],
            'data' => $json,
            'data_hash' => hash('sha256', $json),
            'locked_by' => $user->id,
            'locked_at' => now(),
            'correction_reason' => $meta['correction_reason'] ?? null,
            'correction_approved_by' => $meta['correction_approved_by'] ?? null,
        ]);
    }

    /**
     * The academic profile: identity, enrolment timeline, term records,
     * previous schools and transfers.
     *
     * @param  bool  $publishedOnly  students and parents see only released terms
     */
    public function profile(Student $student, bool $publishedOnly = false): array
    {
        $schoolId = (int) $student->school_id;

        $enrollments = DB::table('student_enrollments')
            ->leftJoin('academic_sessions', 'academic_sessions.id', '=', 'student_enrollments.session_id')
            ->leftJoin('classes', 'classes.id', '=', 'student_enrollments.class_id')
            ->leftJoin('arms', 'arms.id', '=', 'student_enrollments.arm_id')
            ->where('student_enrollments.school_id', $schoolId)
            ->where('student_enrollments.student_id', $student->id)
            ->orderBy('academic_sessions.start_date')
            ->get([
                'student_enrollments.id', 'academic_sessions.name as session', 'classes.name as class', 'arms.name as arm',
                'student_enrollments.status', 'student_enrollments.outcome', 'student_enrollments.enrolled_on',
                'student_enrollments.closed_on', 'student_enrollments.destination_school', 'student_enrollments.outcome_remarks',
            ]);

        $snapshots = TermSnapshot::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->orderBy('id')
            ->get();

        if ($publishedOnly) {
            $snapshots = $snapshots->filter(fn ($s) => $s->term_id === null
                || \App\Models\ResultRelease::isReleasedFor($schoolId, (int) $s->term_id, $s->class_id ? (int) $s->class_id : null));
        }

        $current = $snapshots->whereNull('superseded_at')->values();

        $terms = $current->map(fn (TermSnapshot $s) => [
            'id' => $s->id,
            'session' => $s->session_name,
            'term' => $s->term_name,
            'class' => $s->class_name,
            'version' => $s->version,
            'source' => $s->source,
            'provenance' => $s->provenance,
            'provenance_label' => TermSnapshot::PROVENANCE[$s->provenance] ?? $s->provenance,
            'verified' => $s->isVerified(),
            'partial_term' => $s->partial_term,
            'corrected' => $s->version > 1,
            'correction_reason' => $publishedOnly ? null : $s->correction_reason,
            'intact' => $s->intact(),
            'figures' => $this->publicFigures($s->figures(), $publishedOnly),
        ])->values();

        // Session averages: the mean of the session's term averages.
        $bySession = $terms->groupBy('session')->map(function ($rows, $session) {
            $averages = $rows->pluck('figures.average')->filter(fn ($v) => $v !== null);

            return [
                'session' => $session,
                'terms' => $rows->count(),
                'session_average' => $averages->isNotEmpty() ? round($averages->avg(), 2) : null,
            ];
        })->values();

        $previous = PreviousSchoolRecord::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'school' => $r->source_school_name,
                'provenance' => $r->provenance,
                'provenance_label' => TermSnapshot::PROVENANCE[$r->provenance] ?? $r->provenance,
                'verification_status' => $r->verification_status,
                'amends_id' => $r->amends_id,
                'amendment_reason' => $r->amendment_reason,
                'received_at' => $r->created_at?->toIso8601String(),
                'records' => $r->content(),
            ]);

        $transfers = Transfer::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where(fn ($q) => $q->where('student_id', $student->id)->orWhere('new_student_id', $student->id))
            ->orderBy('id')
            ->get(['id', 'reference', 'direction', 'status', 'other_school_name', 'requested_transfer_date', 'completed_at']);

        $timeline = collect($enrollments)->map(fn ($e) => [
            'kind' => 'enrolment',
            'session' => $e->session,
            'class' => trim(($e->class ?? '').' '.($e->arm ?? '')),
            'status' => $e->status,
            'outcome' => $e->outcome,
            'from' => $e->enrolled_on ? substr((string) $e->enrolled_on, 0, 10) : null,
            'to' => $e->closed_on ? substr((string) $e->closed_on, 0, 10) : null,
            'destination' => $e->destination_school,
        ])->values();

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->user?->name,
                'admission_number' => $student->admission_number,
                'status' => $student->status,
                'class' => $student->currentClass?->name,
            ],
            'timeline' => $timeline,
            'promotions' => $timeline->filter(fn ($t) => $t['outcome'] !== null)->values(),
            'terms' => $terms,
            'sessions' => $bySession,
            'partial_history' => $terms->isEmpty() || $terms->contains(fn ($t) => ! $t['verified']) || $previous->contains(fn ($p) => $p['verification_status'] !== 'verified'),
            'previous_schools' => $previous,
            'transfers' => $transfers,
            'superseded_versions' => $publishedOnly ? 0 : $snapshots->whereNotNull('superseded_at')->count(),
        ];
    }

    /** Parents and students do not see class comparisons or staff-only remarks. */
    private function publicFigures(array $figures, bool $publishedOnly): array
    {
        if (! $publishedOnly) {
            return $figures;
        }

        unset($figures['class_average'], $figures['broadsheet_reference']);

        return $figures;
    }

    /** Locked terms for a session range, for a transcript. */
    public function currentSnapshots(int $schoolId, int $studentId)
    {
        return TermSnapshot::withoutGlobalScopes()
            ->where('term_snapshots.school_id', $schoolId)
            ->where('term_snapshots.student_id', $studentId)
            ->whereNull('term_snapshots.superseded_at')
            ->leftJoin('terms', 'terms.id', '=', 'term_snapshots.term_id')
            ->orderByRaw('terms.start_date IS NULL')
            ->orderBy('terms.start_date')
            ->orderBy('term_snapshots.id')
            ->select('term_snapshots.*')
            ->get();
    }

    public function termOf(int $termId): ?Term
    {
        return Term::withoutGlobalScopes()->find($termId);
    }
}
