<?php

namespace App\Services\History;

use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\HistoryImportBatch;
use App\Models\School;
use App\Models\Student;
use App\Models\Term;
use App\Models\TermSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Exceptions\DomainRefusal;

/**
 * Older terms from paper or Excel (history instructions §4.5).
 *
 * Upload → validate → preview with every error → confirm. An invalid row is
 * never dropped silently: it stays in the error report, which can be
 * downloaded. What is imported is marked "uploaded, not yet verified" until a
 * person checks it, and partial history is shown as partial.
 */
class HistoryImportService
{
    public const COLUMNS = ['admission_number', 'session', 'term', 'class', 'subject', 'ca', 'exam', 'total', 'grade', 'remark'];

    public function template(): string
    {
        return implode(',', self::COLUMNS)."\n"
            ."2026/0001,2023/2024,First Term,JSS 1,Mathematics,32,48,80,A1,Excellent\n";
    }

    public function preview(int $schoolId, User $user, string $csv, ?string $filename): HistoryImportBatch
    {
        $lines = preg_split('/\r\n|\r|\n/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv)));

        if (! $lines || count($lines) < 2) {
            throw new DomainRefusal('The file has no rows under its heading.');
        }

        $header = array_map(fn ($h) => strtolower(trim(str_replace(' ', '_', $h))), str_getcsv(array_shift($lines)));
        $missing = array_diff(['admission_number', 'session', 'term', 'subject', 'total'], $header);

        if ($missing !== []) {
            throw new DomainRefusal('Missing column(s): '.implode(', ', $missing).'. Download the template for the layout.');
        }

        $students = Student::withoutGlobalScopes()->where('school_id', $schoolId)->pluck('id', 'admission_number');
        $existing = TermSnapshot::withoutGlobalScopes()->where('school_id', $schoolId)->whereNull('superseded_at')
            ->get(['student_id', 'session_name', 'term_name'])
            ->map(fn ($s) => $s->student_id.'|'.mb_strtolower((string) $s->session_name).'|'.mb_strtolower((string) $s->term_name))
            ->flip();

        $rows = [];
        $errors = [];
        $seen = [];

        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }

            $n = $i + 2;
            $cells = str_getcsv($line);
            $row = [];

            foreach ($header as $k => $col) {
                $row[$col] = trim((string) ($cells[$k] ?? ''));
            }

            $problems = [];
            $studentId = $students[$row['admission_number']] ?? null;

            if (! $studentId) {
                $problems[] = "No student with admission number '{$row['admission_number']}' in this school.";
            }

            foreach (['session', 'term', 'subject'] as $col) {
                if (($row[$col] ?? '') === '') {
                    $problems[] = "The {$col} is empty.";
                }
            }

            foreach (['ca', 'exam', 'total'] as $col) {
                $v = $row[$col] ?? '';
                if ($v !== '' && (! is_numeric($v) || (float) $v < 0 || (float) $v > 100)) {
                    $problems[] = "The {$col} '{$v}' is not a mark between 0 and 100.";
                }
            }

            if (($row['total'] ?? '') === '') {
                $problems[] = 'The total is empty.';
            }

            if (is_numeric($row['ca'] ?? null) && is_numeric($row['exam'] ?? null) && is_numeric($row['total'] ?? null)
                && abs((float) $row['ca'] + (float) $row['exam'] - (float) $row['total']) > 0.01) {
                $problems[] = "CA {$row['ca']} plus exam {$row['exam']} does not make the total {$row['total']}.";
            }

            $key = $studentId.'|'.mb_strtolower($row['session']).'|'.mb_strtolower($row['term']);
            $subjectKey = $key.'|'.mb_strtolower($row['subject']);

            if (isset($seen[$subjectKey])) {
                $problems[] = "Duplicate of row {$seen[$subjectKey]}.";
            }
            $seen[$subjectKey] = $n;

            if ($studentId && isset($existing[$key])) {
                $problems[] = 'This student already has a record for that term. Corrections go through the record, not an import.';
            }

            if ($problems !== []) {
                $errors[] = ['row' => $n, 'values' => $row, 'problems' => $problems];
            } else {
                $rows[] = $row + ['student_id' => $studentId, 'row' => $n];
            }
        }

        return HistoryImportBatch::create([
            'school_id' => $schoolId,
            'uploaded_by' => $user->id,
            'filename' => $filename,
            'status' => 'previewed',
            'rows' => $rows,
            'errors' => $errors,
            'valid_rows' => count($rows),
            'error_rows' => count($errors),
        ]);
    }

    /** CSV of every rejected row and why. */
    public function errorReport(HistoryImportBatch $batch): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_merge(['row'], self::COLUMNS, ['problems']));

        foreach ($batch->errors ?? [] as $e) {
            fputcsv($out, array_merge([$e['row']], array_map(fn ($c) => $e['values'][$c] ?? '', self::COLUMNS), [implode(' ', $e['problems'])]));
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * Write the valid rows, one snapshot per student per term. Rows with
     * errors stay out until fixed and uploaded again.
     */
    public function commit(HistoryImportBatch $batch, User $user): int
    {
        if ($batch->status !== 'previewed') {
            throw new DomainRefusal('This file has already been imported.');
        }

        $schoolName = School::find($batch->school_id)?->name;

        return DB::transaction(function () use ($batch, $user, $schoolName) {
            $groups = collect($batch->rows)->groupBy(fn ($r) => $r['student_id'].'|'.mb_strtolower($r['session']).'|'.mb_strtolower($r['term']));
            $created = 0;

            foreach ($groups as $rows) {
                $first = $rows->first();
                $session = AcademicSession::withoutGlobalScopes()->where('school_id', $batch->school_id)->whereRaw('LOWER(name) = ?', [mb_strtolower($first['session'])])->first();
                $term = $session ? Term::withoutGlobalScopes()->where('session_id', $session->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($first['term'])])->first() : null;
                $student = Student::withoutGlobalScopes()->with('user:id,name')->find($first['student_id']);

                $subjects = $rows->map(fn ($r) => [
                    'subject' => $r['subject'],
                    'state' => 'scored',
                    'components' => array_filter(['ca' => is_numeric($r['ca'] ?? null) ? (float) $r['ca'] : null, 'exam' => is_numeric($r['exam'] ?? null) ? (float) $r['exam'] : null], fn ($v) => $v !== null),
                    'total' => (float) $r['total'],
                    'grade' => $r['grade'] ?? null,
                    'remark' => $r['remark'] ?? null,
                    'position' => null,
                ])->values()->all();

                $totals = array_column($subjects, 'total');

                $data = [
                    'school' => ['id' => $batch->school_id, 'name' => $schoolName],
                    'student' => ['id' => $student->id, 'name' => $student->user?->name, 'admission_number' => $student->admission_number],
                    'session' => $first['session'],
                    'term' => $first['term'],
                    'class' => $first['class'] ?? null,
                    'subjects' => $subjects,
                    'total' => round(array_sum($totals), 2),
                    'average' => round(array_sum($totals) / max(1, count($totals)), 2),
                    'grade' => null,
                    'position' => null,
                    // The scale used then is not known from a spreadsheet; the
                    // grades are kept exactly as written.
                    'grading_scale' => null,
                    'scheme' => null,
                    'imported_from' => $batch->filename,
                    'imported_batch' => $batch->id,
                ];

                $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

                TermSnapshot::create([
                    'school_id' => $batch->school_id,
                    'student_id' => $student->id,
                    'term_id' => $term?->id,
                    'session_id' => $session?->id,
                    'session_name' => $first['session'],
                    'term_name' => $first['term'],
                    'class_name' => $first['class'] ?? null,
                    'version' => 1,
                    'source' => 'legacy_import',
                    'provenance' => 'uploaded_unverified',
                    'data' => $json,
                    'data_hash' => hash('sha256', $json),
                    'locked_by' => $user->id,
                    'locked_at' => now(),
                ]);

                $created++;
            }

            $batch->update(['status' => 'committed', 'committed_at' => now()]);

            AuditLog::create([
                'school_id' => $batch->school_id,
                'user_id' => $user->id,
                'action' => 'history.imported',
                'auditable_type' => HistoryImportBatch::class,
                'auditable_id' => $batch->id,
                'new_values' => ['terms_created' => $created, 'rows' => $batch->valid_rows, 'errors' => $batch->error_rows],
            ]);

            return $created;
        });
    }
}
