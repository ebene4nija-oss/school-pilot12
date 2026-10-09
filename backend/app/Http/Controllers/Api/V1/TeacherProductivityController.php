<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CaScheme;
use App\Models\Homework;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Services\GradingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TeacherProductivityController extends Controller
{
    /**
     * 1. Bulk Grading Endpoint
     */
    public function bulkGrading(Request $request, GradingService $grading)
    {
        $schoolId = $this->schoolId();
        // The school's CA split and grading scale, like single score entry.
        // This used to allow 20/20/60 only and grade on its own copy of the
        // WAEC ladder, whatever the school had configured.
        $scheme = CaScheme::activeFor($schoolId);

        $validator = Validator::make($request->all(), [
            'term_id' => ['required', \Illuminate\Validation\Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['required', \Illuminate\Validation\Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'scores' => 'required|array',
            'scores.*.student_id' => ['required', \Illuminate\Validation\Rule::exists('students', 'id')->where('school_id', $schoolId)],
            // Marks are checked per part by ScoreInput, against the scheme.
            'scores.*.components' => 'nullable|array',
            'scores.*.comment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($refusal = AssessmentController::notYourSubject($request->user(), $schoolId, (int) $request->subject_id, collect($request->scores)->pluck('student_id')->map(fn ($id) => (int) $id)->all())) {
            return $refusal;
        }

        $savedEntries = [];

        $levels = $this->classLevels($schoolId, collect($request->scores)->pluck('student_id')->all());

        // The scheme in force for this term and section, not today's default.
        $scheme = CaScheme::forTerm($schoolId, (int) $request->term_id, \App\Support\Sections::levelGroupOf(reset($levels) ?: null));

        // Submitted or approved marks are not overwritten in bulk either.
        $locked = $this->lockedRows($schoolId, (int) $request->term_id, (int) $request->subject_id, collect($request->scores)->pluck('student_id')->all());

        if ($locked !== []) {
            return response()->json([
                'message' => 'Some of these marks are locked for review or already approved. Nothing was saved.',
                'code' => 'result_locked',
                'locked' => $locked,
            ], 409);
        }

        $existing = ScoreEntry::where('school_id', $schoolId)
            ->where('term_id', $request->term_id)
            ->where('subject_id', $request->subject_id)
            ->whereIn('student_id', collect($request->scores)->pluck('student_id'))
            ->pluck('component_scores', 'student_id');

        // Every row is checked before anything is saved.
        $resolved = [];
        foreach ($request->scores as $i => $item) {
            $resolved[$i] = \App\Services\ScoreInput::resolve($scheme, $item, $existing[$item['student_id']] ?? null, "scores.{$i}.");
        }

        DB::transaction(function () use ($request, $schoolId, $scheme, $grading, $levels, $resolved, &$savedEntries) {
            foreach ($request->scores as $i => $item) {
                $firstCa = $resolved[$i]['first_ca'];
                $secondCa = $resolved[$i]['second_ca'];
                $exam = $resolved[$i]['exam'];

                $computed = $grading->total($scheme, $firstCa, $secondCa, $exam, $levels[$item['student_id']] ?? null);
                $total = $computed['percentage'];
                $grade = $computed['grade'];

                $data = [
                    'first_ca' => $firstCa,
                    'second_ca' => $secondCa,
                    'exam' => $exam,
                    'component_scores' => $resolved[$i]['component_scores'],
                    'total_score' => $total,
                    'grade' => $grade,
                ];

                if (!empty($item['comment'])) {
                    $data['teacher_comment'] = $item['comment'];
                }

                $savedEntries[] = ScoreEntry::updateOrCreate(
                    [
                        'school_id' => $schoolId,
                        'term_id' => $request->term_id,
                        'student_id' => $item['student_id'],
                        'subject_id' => $request->subject_id,
                    ],
                    $data
                );
            }
        });

        return response()->json([
            'message' => 'Bulk scores and grades saved successfully',
            'saved_count' => count($savedEntries),
            'entries' => $savedEntries,
        ]);
    }

    /**
     * 2. Import Scores / Grades from Excel CSV
     */
    public function importScoresCsv(Request $request, GradingService $grading)
    {
        $schoolId = $this->schoolId();

        // Term and subject must be this school's: the rules used to accept any
        // school's ids.
        $validator = Validator::make($request->all(), [
            'term_id' => ['required', \Illuminate\Validation\Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['required', \Illuminate\Validation\Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $scheme = CaScheme::forTerm($schoolId, (int) $request->term_id);
        $rows = array_map('str_getcsv', file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $header = array_map(fn ($h) => $this->headerKey((string) $h), array_shift($rows) ?? []);

        /*
         * Columns are found by heading, in any order: the student by
         * `student_id` or `admission_number`, and a mark per part of the
         * school's scheme by the part's key (`ca3`) or name ("3rd CA"). The
         * classic headings first_ca / second_ca / exam still work for a
         * two-CA school. Without recognisable headings the old fixed order
         * (student_id, first CA, second CA, exam) is assumed.
         */
        $partColumns = [];
        foreach ($scheme->parts() as $part) {
            foreach ([$part['key'], $this->headerKey($part['name'])] as $candidate) {
                $at = array_search($candidate, $header, true);
                if ($at !== false) {
                    $partColumns[$part['key']] = $at;
                    break;
                }
            }
        }

        if ($scheme->isClassic()) {
            // The original headings, for any part not already matched by key
            // or name (`exam` alone would otherwise hide first_ca/second_ca).
            foreach (['first_ca' => 'ca1', 'second_ca' => 'ca2', 'exam' => 'exam'] as $legacy => $key) {
                $at = array_search($legacy, $header, true);
                if ($at !== false && ! isset($partColumns[$key])) {
                    $partColumns[$key] = $at;
                }
            }
            if ($partColumns === []) {
                $partColumns = ['ca1' => 1, 'ca2' => 2, 'exam' => 3];
            }
        }

        if ($partColumns === []) {
            $names = implode(', ', array_column($scheme->parts(), 'name'));

            return response()->json(['message' => "No mark columns found. Use a heading for each part: {$names}. Download the template to see the layout."], 422);
        }

        $idColumn = array_search('student_id', $header, true);
        $admissionColumn = array_search('admission_number', $header, true);
        if ($admissionColumn === false) {
            $admissionColumn = array_search('admission_no', $header, true);
        }
        if ($idColumn === false && $admissionColumn === false) {
            $idColumn = 0;
        }

        $importedCount = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $student = $idColumn !== false && trim((string) ($row[$idColumn] ?? '')) !== ''
                ? Student::where('school_id', $schoolId)->find(trim((string) $row[$idColumn]))
                : ($admissionColumn !== false
                    ? Student::where('school_id', $schoolId)->where('admission_number', trim((string) ($row[$admissionColumn] ?? '')))->first()
                    : null);

            if (! $student) {
                $errors[] = "Row {$rowNum}: student not found in this school.";

                continue;
            }

            if (AssessmentController::notYourSubject($request->user(), $schoolId, (int) $request->subject_id, [(int) $student->id])) {
                $errors[] = "Row {$rowNum}: you do not teach this subject to this student's class.";

                continue;
            }

            if ($this->lockedRows($schoolId, (int) $request->term_id, (int) $request->subject_id, [$student->id]) !== []) {
                $errors[] = "Row {$rowNum}: this student's mark is locked for review or already approved.";

                continue;
            }

            $components = [];
            foreach ($partColumns as $key => $at) {
                $value = trim((string) ($row[$at] ?? ''));
                if ($value !== '') {
                    $components[$key] = $value;
                }
            }

            $existing = ScoreEntry::where('school_id', $schoolId)
                ->where('term_id', $request->term_id)
                ->where('student_id', $student->id)
                ->where('subject_id', $request->subject_id)
                ->first();

            try {
                $marks = \App\Services\ScoreInput::resolve($scheme, ['components' => $components], $existing?->component_scores);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $errors[] = "Row {$rowNum}: ".collect($e->errors())->flatten()->implode(' ');

                continue;
            }

            // Was a three-step ladder (A1, B2, B3, else "F"), so a 60 was a fail.
            $computed = $grading->total($scheme, $marks['first_ca'], $marks['second_ca'], $marks['exam'], $this->classLevels($schoolId, [$student->id])[$student->id] ?? null);

            ScoreEntry::updateOrCreate(
                [
                    'school_id' => $schoolId,
                    'term_id' => $request->term_id,
                    'student_id' => $student->id,
                    'subject_id' => $request->subject_id,
                ],
                [
                    'first_ca' => $marks['first_ca'],
                    'second_ca' => $marks['second_ca'],
                    'exam' => $marks['exam'],
                    'component_scores' => $marks['component_scores'],
                    'total_score' => $computed['percentage'],
                    'grade' => $computed['grade'],
                ]
            );
            $importedCount++;
        }

        return response()->json([
            'message' => "Imported {$importedCount} score(s).".($errors ? ' '.count($errors).' row(s) skipped.' : ''),
            'imported_count' => $importedCount,
            'errors' => $errors,
        ]);
    }

    /**
     * A score sheet to fill in: one row per student in the class, one column
     * per part of the school's marking scheme. Uploaded unchanged it imports.
     */
    public function scoreTemplate(Request $request)
    {
        $schoolId = $this->schoolId();

        $request->validate([
            'class_id' => ['required', \Illuminate\Validation\Rule::exists('classes', 'id')->where('school_id', $schoolId)],
        ]);

        $scheme = CaScheme::activeFor($schoolId);
        $students = Student::where('school_id', $schoolId)
            ->where('class_id', $request->class_id)
            ->where('status', 'active')
            ->with('user:id,name')
            ->orderBy('admission_number')
            ->get();

        $cell = fn ($v) => '"'.str_replace('"', '""', (string) $v).'"';
        $lines = [implode(',', array_map($cell, array_merge(
            ['admission_number', 'student_name'],
            array_map(fn ($p) => "{$p['name']} ({$p['max']})", $scheme->parts()),
        )))];

        foreach ($students as $student) {
            $lines[] = implode(',', array_merge(
                [$cell($student->admission_number), $cell($student->user?->name)],
                array_fill(0, count($scheme->parts()), ''),
            ));
        }

        return response(implode(chr(13).chr(10), $lines).chr(13).chr(10), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="score-sheet.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** "3rd CA (10)" → "3rd_ca"; strips a byte-order mark and a "(max)" suffix. */
    private function headerKey(string $heading): string
    {
        $heading = preg_replace('/^\xEF\xBB\xBF/', '', $heading);
        $heading = preg_replace('/\(\s*\d+\s*\)\s*$/', '', $heading);

        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($heading))), '_');
    }

    /**
     * Export Class Scores to CSV / Excel
     */
    public function exportScoresCsv(Request $request)
    {
        $schoolId = $this->schoolId();

        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
            'subject_id' => 'required|exists:subjects,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $scores = ScoreEntry::where('school_id', $schoolId)
            ->where('term_id', $request->term_id)
            ->where('subject_id', $request->subject_id)
            ->with(['student.user'])
            ->get();

        $csvData = [];
        $csvData[] = ['student_id', 'student_name', 'admission_number', 'first_ca', 'second_ca', 'exam', 'total_score', 'grade', 'teacher_comment'];

        foreach ($scores as $s) {
            $csvData[] = [
                $s->student_id,
                $s->student->user->name ?? 'N/A',
                $s->student->admission_number ?? 'N/A',
                $s->first_ca,
                $s->second_ca,
                $s->exam,
                $s->total_score,
                $s->grade,
                $s->teacher_comment ?? '',
            ];
        }

        return response()->json([
            'status' => 'success',
            'export_type' => 'csv_matrix',
            'count' => count($csvData) - 1,
            'rows' => $csvData,
        ]);
    }

    /**
     * 3. Copy Previous Term's Lesson Plans / Homework Tasks
     */
    public function copyPreviousTermLessonPlans(Request $request)
    {
        $schoolId = $this->schoolId();

        $validator = Validator::make($request->all(), [
            'source_class_id' => 'required|exists:classes,id',
            'target_class_id' => 'required|exists:classes,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $sourceHomework = Homework::where('school_id', $schoolId)
            ->where('class_id', $request->source_class_id)
            ->get();

        $copiedCount = 0;
        foreach ($sourceHomework as $hw) {
            Homework::create([
                'school_id' => $schoolId,
                'class_id' => $request->target_class_id,
                'subject_id' => $hw->subject_id,
                'teacher_id' => $request->user()->id,
                'title' => $hw->title,
                'description' => $hw->description,
                'due_date' => now()->addDays(7)->toDateString(),
            ]);
            $copiedCount++;
        }

        return response()->json([
            'message' => "Copied {$copiedCount} lesson plan / homework tasks to target class.",
            'copied_count' => $copiedCount,
        ]);
    }

    /**
     * 4. Duplicate Question Bank Exams
     */
    public function duplicateExam(Request $request)
    {
        $schoolId = $this->schoolId();

        $validator = Validator::make($request->all(), [
            'subject_id' => 'required|exists:subjects,id',
            'topic'      => 'required|string',
            'new_topic'  => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $existingQuestions = DB::table('question_bank')
            ->where('school_id', $schoolId)
            ->where('subject_id', $request->subject_id)
            ->where('topic', $request->topic)
            ->get();

        $duplicatedCount = 0;
        foreach ($existingQuestions as $q) {
            DB::table('question_bank')->insert([
                'school_id' => $schoolId,
                'subject_id' => $q->subject_id,
                'topic' => $request->new_topic,
                'question' => $q->question,
                'options' => $q->options,
                'correct_answer' => $q->correct_answer,
                'difficulty' => $q->difficulty,
                'question_type' => $q->question_type ?? 'multiple_choice',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $duplicatedCount++;
        }

        return response()->json([
            'message' => "Successfully duplicated {$duplicatedCount} exam questions under topic '{$request->new_topic}'.",
            'duplicated_count' => $duplicatedCount,
        ]);
    }

    /**
     * 5. Reuse Report Card Comments Snippet Bank
     */
    public function getCommentBank(Request $request)
    {
        return response()->json([
            'comment_bank' => [
                'Excellent academic dedication and consistent performance across all subject modules.',
                'Shows commendable effort and active participation in classroom activities.',
                'Good analytical skills; encouraged to maintain focus on time management during exams.',
                'Capable student who has demonstrated significant improvement this term.',
                'Additional practice and regular revision recommended for optimal performance.',
            ]
        ]);
    }

    /**
     * Each student's class level, for choosing their grading scale.
     *
     * @return array<int|string, string|null> keyed by student id
     */
    /**
     * Students whose mark in this subject cannot be written now, with why.
     *
     * @return array<int, array{student_id: int, reason: string}>
     */
    private function lockedRows(int $schoolId, int $termId, int $subjectId, array $studentIds): array
    {
        $workflow = app(\App\Services\Results\BroadsheetWorkflow::class);
        $classes = Student::withoutGlobalScopes()->where('school_id', $schoolId)->whereIn('id', $studentIds)->pluck('class_id', 'id');
        $locked = [];

        foreach ($classes as $studentId => $classId) {
            if (! $classId) {
                continue;
            }

            $reason = $workflow->editBlockReason($schoolId, $termId, (int) $classId, (int) $studentId, $subjectId);

            if ($reason !== null) {
                $locked[] = ['student_id' => (int) $studentId, 'reason' => $reason];
            }
        }

        return $locked;
    }

    private function classLevels(int $schoolId, array $studentIds): array
    {
        return DB::table('students')
            ->leftJoin('classes', 'classes.id', '=', 'students.class_id')
            ->where('students.school_id', $schoolId)
            ->whereIn('students.id', $studentIds)
            ->pluck('classes.level_category', 'students.id')
            ->all();
    }
}
