<?php

namespace App\Services\Results;

use App\Models\Broadsheet;
use App\Models\BroadsheetOverride;
use Illuminate\Support\Facades\DB;

/**
 * The checks a broadsheet must pass before approval (broadsheet spec §10).
 *
 * Errors block approval until fixed; nothing can wave them through. Warnings
 * are things a school may legitimately accept — a child who missed the exam,
 * a subject still blank at term end — but only by someone with authority,
 * with the reason on record. Notices explain how a figure was reached and
 * need no action.
 */
class BroadsheetValidator
{
    /**
     * @param  array<string, mixed>  $calc  ResultCalculator::forClass output
     * @return array{errors: int, warnings: int, unresolved_warnings: int, issues: array<int, array<string, mixed>>}
     */
    public function validate(array $calc, ?Broadsheet $broadsheet = null): array
    {
        $issues = [];
        $subjects = collect($calc['subjects'])->keyBy('id');
        $parts = collect($calc['scheme']['parts'])->keyBy('key');

        if ($calc['scheme']['total_weight'] <= 0 || $parts->isEmpty()) {
            $issues[] = $this->issue('error', 'invalid_scheme', 'scheme', 'The CA scheme has no marks to award, so nothing can be calculated.');
        }

        if ($calc['students'] === []) {
            $issues[] = $this->issue('error', 'no_students', 'class', 'No students are enrolled in this class for the term.');
        }

        foreach ($calc['students'] as $student) {
            foreach ($student['results'] as $subjectId => $r) {
                $subject = $subjects->get($subjectId)['name'] ?? 'a subject';
                $key = "{$student['id']}:{$subjectId}";
                $who = "{$student['name']} ({$subject})";

                if ($r['state'] === 'not_entered') {
                    $issues[] = $this->issue('warning', 'missing_score', $key, "{$who} has no score entered.", $student['id'], $subjectId);
                }

                if ($r['state'] === 'scored') {
                    foreach ((array) $r['components'] as $partKey => $mark) {
                        $max = $parts->get($partKey)['max'] ?? null;

                        if ($mark !== null && ((float) $mark < 0 || ($max !== null && (float) $mark > $max))) {
                            $issues[] = $this->issue('error', 'score_out_of_range', "{$key}:{$partKey}", "{$who}: {$mark} is outside 0 to ".($max ?? '?')." for ".($parts->get($partKey)['name'] ?? $partKey).'.', $student['id'], $subjectId);
                        }
                    }

                    if ($r['total'] < 0 || $r['total'] > 100) {
                        $issues[] = $this->issue('error', 'score_out_of_range', "{$key}:total", "{$who}: total {$r['total']} is outside 0 to 100.", $student['id'], $subjectId);
                    }

                    if ($r['grade'] === null || $r['grade'] === '') {
                        $issues[] = $this->issue('error', 'missing_grade', $key, "{$who}: {$r['total']} falls outside every grade on the scale.", $student['id'], $subjectId);
                    }

                    if (! $r['applies']) {
                        $issues[] = $this->issue('warning', 'enrollment_mismatch', $key, "{$who} has a score but is not enrolled in the subject.", $student['id'], $subjectId);
                    }
                }

                if ($r['state'] === 'absent') {
                    $policy = $calc['config']['absence_policy'] === 'zero' ? 'counted as zero' : 'left out of the average';
                    $issues[] = $this->issue('notice', 'absent', $key, "{$who} was absent; {$policy} under the school's policy.", $student['id'], $subjectId);
                }

                if ($r['state'] === 'exempt') {
                    $issues[] = $this->issue('notice', 'exempt', $key, "{$who} is exempt and is left out of the average.", $student['id'], $subjectId);
                }
            }

            if ($student['partial_term']) {
                $issues[] = $this->issue('notice', 'partial_term', (string) $student['id'], "{$student['name']} joined or left during the term; their results cover part of it.", $student['id']);
            }
        }

        foreach ($this->duplicates($calc) as $dup) {
            $issues[] = $this->issue('error', 'duplicate_record', "{$dup->student_id}:{$dup->subject_id}", 'A student has '.$dup->n.' score records for the same subject this term. Remove the extras before approval.', (int) $dup->student_id, (int) $dup->subject_id);
        }

        if ($broadsheet) {
            $submitted = $broadsheet->submissions()->get()->keyBy('subject_id');

            foreach ($calc['subjects'] as $subject) {
                $status = $submitted->get($subject['id'])?->status ?? 'draft';

                if ($status !== 'submitted') {
                    $issues[] = $this->issue('error', 'subject_not_submitted', (string) $subject['id'], "{$subject['name']} has not been submitted by its teacher.", null, $subject['id']);
                }
            }

            $overrides = BroadsheetOverride::withoutGlobalScopes()
                ->where('broadsheet_id', $broadsheet->id)
                ->get()
                ->keyBy(fn ($o) => $o->issue_code.'|'.$o->issue_key);

            foreach ($issues as &$issue) {
                if ($issue['severity'] === 'warning' && ($o = $overrides->get($issue['code'].'|'.$issue['key']))) {
                    $issue['overridden'] = true;
                    $issue['override_reason'] = $o->reason;
                }
            }
            unset($issue);
        }

        $count = fn (string $severity) => count(array_filter($issues, fn ($i) => $i['severity'] === $severity));

        return [
            'errors' => $count('error'),
            'warnings' => $count('warning'),
            'notices' => $count('notice'),
            'unresolved_warnings' => count(array_filter($issues, fn ($i) => $i['severity'] === 'warning' && ! $i['overridden'])),
            'issues' => $issues,
        ];
    }

    private function issue(string $severity, string $code, string $key, string $message, ?int $studentId = null, ?int $subjectId = null): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'key' => $key,
            'message' => $message,
            'student_id' => $studentId,
            'subject_id' => $subjectId,
            'overridden' => false,
            'override_reason' => null,
        ];
    }

    private function duplicates(array $calc)
    {
        $studentIds = array_column($calc['students'], 'id');

        return DB::table('score_entries')
            ->where('school_id', $calc['school_id'])
            ->where('term_id', $calc['term']['id'])
            ->whereIn('student_id', $studentIds ?: [0])
            ->whereNull('deleted_at')
            ->selectRaw('student_id, subject_id, COUNT(*) as n')
            ->groupBy('student_id', 'subject_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();
    }
}
