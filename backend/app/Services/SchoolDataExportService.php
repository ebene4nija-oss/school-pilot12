<?php

namespace App\Services;

use App\Models\SchoolDataExport;
use App\Models\Student;
use App\Support\ZipWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a school's data archive: one CSV per table, plus a manifest.
 *
 * Tables are dumped generically off the schema rather than hand-listed
 * column by column. A hand-written exporter starts complete and silently rots
 * — a column added next term is a column missing from every export after it,
 * and nobody notices until a school needs the file.
 */
class SchoolDataExportService
{
    /**
     * What a school gets. Ordered roughly as someone would read it.
     *
     * Deliberately excludes `audit_logs`, `login_histories`,
     * `personal_access_tokens` and the AI/tutor transcripts: an export is the
     * school's records, not a copy of its security trail or of every child's
     * private conversation with the tutor.
     */
    private const TABLES = [
        'academic_sessions',
        'terms',
        'classes',
        'arms',
        'subjects',
        'class_subjects',
        'students',
        'student_enrollments',
        'student_class_history',
        'student_subjects',
        'guardians',
        'staff',
        'teacher_profiles',
        'teacher_subjects',
        'class_teacher_assignments',
        'score_entries',
        'attendance_records',
        'fee_structures',
        'invoices',
        'invoice_items',
        'payments',
        'homework',
        'result_releases',
    ];

    /**
     * The four application-encrypted columns. They come back as ciphertext
     * through the query builder, so a raw dump would ship unreadable blobs;
     * they are excluded here and re-added, decrypted, only when the request
     * explicitly asked for them.
     */
    private const MEDICAL_COLUMNS = [
        'blood_group',
        'allergies',
        'medical_notes',
        'emergency_contacts',
    ];

    public function generate(SchoolDataExport $export): void
    {
        $export->update(['status' => 'processing']);

        $schoolId = (int) $export->school_id;
        $disk = Storage::disk('local');
        $relativePath = "exports/school-{$schoolId}/export-{$export->id}.zip";

        $disk->makeDirectory(dirname($relativePath));
        $absolutePath = $disk->path($relativePath);

        try {
            $zip = new ZipWriter($absolutePath);
            $counts = [];

            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'school_id')) {
                    continue;
                }

                $columns = array_values(array_diff(
                    Schema::getColumnListing($table),
                    $table === 'students' ? self::MEDICAL_COLUMNS : []
                ));

                [$csv, $rows] = $this->tableToCsv($table, $columns, $schoolId);

                $zip->add("{$table}.csv", $csv);
                $counts[$table] = $rows;
            }

            // Guardian ↔ student links carry no school_id of their own, so
            // they are filtered through the students they belong to.
            [$linkCsv, $linkRows] = $this->guardianLinksToCsv($schoolId);
            $zip->add('student_guardian.csv', $linkCsv);
            $counts['student_guardian'] = $linkRows;

            // A readable roster, because a table of foreign keys is not what
            // a head teacher opening this file is looking for.
            [$rosterCsv, $rosterRows] = $this->readableRosterToCsv($schoolId);
            $zip->add('roster-readable.csv', $rosterCsv);
            $counts['roster-readable'] = $rosterRows;

            if ($export->includes_medical) {
                [$medicalCsv, $medicalRows] = $this->medicalToCsv($schoolId);
                $zip->add('students-medical.csv', $medicalCsv);
                $counts['students-medical'] = $medicalRows;
            }

            $zip->add('manifest.json', json_encode([
                'school_id' => $schoolId,
                'generated_at' => now()->toIso8601String(),
                'schema_version' => $this->schemaVersion(),
                'includes_medical' => (bool) $export->includes_medical,
                'row_counts' => $counts,
                'notes' => 'One CSV per table. Foreign keys refer to ids within this archive. '
                    . 'roster-readable.csv is the same students with class, arm and guardian names filled in.',
            ], JSON_PRETTY_PRINT));

            $zip->finish();

            $export->update([
                'status' => 'complete',
                'file_path' => $relativePath,
                'file_size' => $disk->size($relativePath),
                'row_counts' => $counts,
                'completed_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);
        } catch (\Throwable $e) {
            // A half-written archive is worse than none: it opens, looks
            // complete, and is missing whatever came after the failure.
            if (file_exists($absolutePath)) {
                @unlink($absolutePath);
            }

            $export->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'file_path' => null,
            ]);

            throw $e;
        }
    }

    /**
     * @return array{0:string,1:int}
     */
    private function tableToCsv(string $table, array $columns, int $schoolId): array
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $columns);

        $rows = 0;

        // Chunked by id: a school with nine years of score entries must not
        // be held in memory in one result set.
        DB::table($table)
            ->where('school_id', $schoolId)
            ->orderBy('id')
            ->chunkById(1000, function ($chunk) use ($handle, $columns, &$rows) {
                foreach ($chunk as $record) {
                    $record = (array) $record;

                    fputcsv($handle, array_map(
                        fn ($column) => $this->scalar($record[$column] ?? null),
                        $columns
                    ));

                    $rows++;
                }
            });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return [$csv, $rows];
    }

    /**
     * @return array{0:string,1:int}
     */
    private function guardianLinksToCsv(int $schoolId): array
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['student_id', 'guardian_id', 'is_primary']);

        $rows = 0;

        DB::table('student_guardian')
            ->join('students', 'students.id', '=', 'student_guardian.student_id')
            ->where('students.school_id', $schoolId)
            ->orderBy('student_guardian.id')
            ->select('student_guardian.student_id', 'student_guardian.guardian_id', 'student_guardian.is_primary')
            ->chunk(1000, function ($chunk) use ($handle, &$rows) {
                foreach ($chunk as $link) {
                    fputcsv($handle, [$link->student_id, $link->guardian_id, $link->is_primary]);
                    $rows++;
                }
            });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return [$csv, $rows];
    }

    /**
     * @return array{0:string,1:int}
     */
    private function readableRosterToCsv(int $schoolId): array
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            'admission_number', 'name', 'email', 'gender', 'date_of_birth',
            'class', 'arm', 'status', 'guardian', 'guardian_phone',
        ]);

        $rows = 0;

        DB::table('students')
            ->leftJoin('users', 'users.id', '=', 'students.user_id')
            ->leftJoin('classes', 'classes.id', '=', 'students.class_id')
            ->leftJoin('arms', 'arms.id', '=', 'students.arm_id')
            ->where('students.school_id', $schoolId)
            ->orderBy('students.id')
            ->select([
                'students.id',
                'students.admission_number',
                'users.name',
                'users.email',
                'students.gender',
                'students.date_of_birth',
                'classes.name as class_name',
                'arms.name as arm_name',
                'students.status',
            ])
            ->chunkById(500, function ($chunk) use ($handle, &$rows) {
                $guardians = $this->guardiansFor($chunk->pluck('id')->all());

                foreach ($chunk as $student) {
                    $guardian = $guardians[$student->id] ?? null;

                    fputcsv($handle, [
                        $student->admission_number,
                        $student->name,
                        $student->email,
                        $student->gender,
                        $student->date_of_birth,
                        $student->class_name,
                        $student->arm_name,
                        $student->status,
                        $guardian->guardian_name ?? null,
                        $guardian->guardian_phone ?? null,
                    ]);

                    $rows++;
                }
            }, 'students.id', 'id');

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return [$csv, $rows];
    }

    /**
     * Primary guardian per student, in one query for the chunk rather than
     * one per child.
     */
    private function guardiansFor(array $studentIds)
    {
        if (empty($studentIds)) {
            return collect();
        }

        return DB::table('student_guardian')
            ->join('guardians', 'guardians.id', '=', 'student_guardian.guardian_id')
            ->leftJoin('users', 'users.id', '=', 'guardians.user_id')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'guardians.user_id')
            ->whereIn('student_guardian.student_id', $studentIds)
            ->orderByDesc('student_guardian.is_primary')
            ->select([
                'student_guardian.student_id',
                'users.name as guardian_name',
                'user_profiles.phone as guardian_phone',
            ])
            ->get()
            ->keyBy('student_id');
    }

    /**
     * Health data, decrypted, in its own file.
     *
     * Separate so that the sensitive part of an archive is one deletable file
     * rather than four columns buried in the roster — and so its row count
     * shows up in the manifest as its own line.
     *
     * @return array{0:string,1:int}
     */
    private function medicalToCsv(int $schoolId): array
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['student_id', 'admission_number', 'blood_group', 'allergies', 'medical_notes', 'emergency_contacts']);

        $rows = 0;

        Student::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->orderBy('id')
            ->chunk(500, function ($chunk) use ($handle, &$rows) {
                foreach ($chunk as $student) {
                    fputcsv($handle, [
                        $student->id,
                        $student->admission_number,
                        $student->blood_group,
                        is_array($student->allergies) ? implode('; ', $student->allergies) : $student->allergies,
                        $student->medical_notes,
                        is_array($student->emergency_contacts)
                            ? json_encode($student->emergency_contacts)
                            : $student->emergency_contacts,
                    ]);

                    $rows++;
                }
            });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return [$csv, $rows];
    }

    private function scalar($value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }

    /**
     * The last migration applied, so a future importer can tell which shape
     * of the schema an archive was written against.
     */
    private function schemaVersion(): ?string
    {
        if (! Schema::hasTable('migrations')) {
            return null;
        }

        return DB::table('migrations')->orderByDesc('id')->value('migration');
    }
}
