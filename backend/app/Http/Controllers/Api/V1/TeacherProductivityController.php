<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Homework;
use App\Models\ScoreEntry;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TeacherProductivityController extends Controller
{
    /**
     * 1. Bulk Grading Endpoint
     */
    public function bulkGrading(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

        $validator = Validator::make($request->all(), [
            'term_id' => ['required', \Illuminate\Validation\Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['required', \Illuminate\Validation\Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'scores' => 'required|array',
            'scores.*.student_id' => ['required', \Illuminate\Validation\Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'scores.*.first_ca' => 'nullable|numeric|min:0|max:20',
            'scores.*.second_ca' => 'nullable|numeric|min:0|max:20',
            'scores.*.exam' => 'nullable|numeric|min:0|max:60',
            'scores.*.comment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $savedEntries = [];

        DB::transaction(function () use ($request, $schoolId, &$savedEntries) {
            foreach ($request->scores as $item) {
                $firstCa = $item['first_ca'] ?? 0;
                $secondCa = $item['second_ca'] ?? 0;
                $exam = $item['exam'] ?? 0;
                $total = $firstCa + $secondCa + $exam;

                $grade = 'F';
                if ($total >= 75) $grade = 'A1';
                elseif ($total >= 70) $grade = 'B2';
                elseif ($total >= 65) $grade = 'B3';
                elseif ($total >= 60) $grade = 'C4';
                elseif ($total >= 55) $grade = 'C5';
                elseif ($total >= 50) $grade = 'C6';
                elseif ($total >= 45) $grade = 'D7';
                elseif ($total >= 40) $grade = 'E8';

                $data = [
                    'first_ca' => $firstCa,
                    'second_ca' => $secondCa,
                    'exam' => $exam,
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
    public function importScoresCsv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
            'subject_id' => 'required|exists:subjects,id',
            'file' => 'required|file|mimes:csv,txt,xlsx',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;
        $file = $request->file('file');
        $rows = array_map('str_getcsv', file($file->getRealPath()));
        $header = array_shift($rows);

        $importedCount = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;
            if (count($row) < 4) {
                $errors[] = "Row {$rowNum}: Insufficient columns. Expected: student_id, first_ca, second_ca, exam";
                continue;
            }

            $studentId = trim($row[0]);
            $firstCa = (float)trim($row[1]);
            $secondCa = (float)trim($row[2]);
            $exam = (float)trim($row[3]);

            $student = Student::where('school_id', $schoolId)->find($studentId);
            if (!$student) {
                $errors[] = "Row {$rowNum}: Student ID {$studentId} not found in school.";
                continue;
            }

            $total = $firstCa + $secondCa + $exam;
            $grade = 'F';
            if ($total >= 75) $grade = 'A1';
            elseif ($total >= 70) $grade = 'B2';
            elseif ($total >= 65) $grade = 'B3';

            ScoreEntry::updateOrCreate(
                [
                    'school_id' => $schoolId,
                    'term_id' => $request->term_id,
                    'student_id' => $studentId,
                    'subject_id' => $request->subject_id,
                ],
                [
                    'first_ca' => $firstCa,
                    'second_ca' => $secondCa,
                    'exam' => $exam,
                    'total_score' => $total,
                    'grade' => $grade,
                ]
            );
            $importedCount++;
        }

        return response()->json([
            'message' => "Successfully imported {$importedCount} student scores.",
            'imported_count' => $importedCount,
            'errors' => $errors,
        ]);
    }

    /**
     * Export Class Scores to CSV / Excel
     */
    public function exportScoresCsv(Request $request)
    {
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

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
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

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
        $schoolId = $request->user()->userProfile ? $request->user()->userProfile->school_id : null;

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
}
