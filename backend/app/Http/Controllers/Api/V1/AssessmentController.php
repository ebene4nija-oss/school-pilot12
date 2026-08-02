<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ScoreEntry;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AssessmentController extends Controller
{
    public function enterScores(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
            'student_id' => 'required|exists:students,id',
            'subject_id' => 'required|exists:subjects,id',
            'first_ca' => 'nullable|numeric|min:0|max:20',
            'second_ca' => 'nullable|numeric|min:0|max:20',
            'exam' => 'nullable|numeric|min:0|max:60',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $firstCa = $request->get('first_ca', 0);
        $secondCa = $request->get('second_ca', 0);
        $exam = $request->get('exam', 0);
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

        $entry = ScoreEntry::updateOrCreate(
            [
                'school_id' => $schoolId,
                'term_id' => $request->term_id,
                'student_id' => $request->student_id,
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

        return response()->json([
            'message' => 'Score entry saved successfully',
            'score_entry' => $entry,
        ]);
    }

    public function generateAiComment(Request $request, $id)
    {
        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $entry = ScoreEntry::where('school_id', $schoolId)->findOrFail($id);

        // Mock LLM comment generation with required pending_approval state
        $generatedComment = "Demonstrates good understanding in assessments, scoring {$entry->total_score}%. Recommending additional practice in problem solving.";

        $entry->update([
            'teacher_comment' => $generatedComment,
            'ai_comment_status' => 'pending_approval',
        ]);

        return response()->json([
            'message' => 'AI comment generated and set to pending approval.',
            'score_entry' => $entry,
        ]);
    }

    public function reviewAiComment(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,edit,reject',
            'edited_comment' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $entry = ScoreEntry::where('school_id', $schoolId)->findOrFail($id);

        if ($request->action === 'approve') {
            $entry->update(['ai_comment_status' => 'approved']);
        } elseif ($request->action === 'edit') {
            $entry->update([
                'teacher_comment' => $request->edited_comment,
                'ai_comment_status' => 'approved',
            ]);
        } else {
            $entry->update([
                'teacher_comment' => null,
                'ai_comment_status' => 'rejected',
            ]);
        }

        return response()->json([
            'message' => "AI comment action '{$request->action}' completed.",
            'score_entry' => $entry,
        ]);
    }

    public function getBroadsheet(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'term_id' => 'required|exists:terms,id',
            'class_id' => 'required|exists:classes,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $schoolId = $user->userProfile ? $user->userProfile->school_id : null;

        $students = Student::where('school_id', $schoolId)
            ->where('class_id', $request->class_id)
            ->with(['user'])
            ->get();

        $broadsheet = [];
        foreach ($students as $student) {
            $scores = ScoreEntry::where('school_id', $schoolId)
                ->where('term_id', $request->term_id)
                ->where('student_id', $student->id)
                ->get();

            $totalScoreSum = $scores->sum('total_score');
            $average = $scores->count() > 0 ? $totalScoreSum / $scores->count() : 0;

            $broadsheet[] = [
                'student_id' => $student->id,
                'student_name' => $student->user ? $student->user->name : 'N/A',
                'admission_number' => $student->admission_number,
                'scores' => $scores,
                'total_score' => $totalScoreSum,
                'average' => round($average, 2),
            ];
        }

        // Rank by average descending
        usort($broadsheet, fn($a, $b) => $b['average'] <=> $a['average']);

        return response()->json([
            'class_id' => $request->class_id,
            'term_id' => $request->term_id,
            'broadsheet' => $broadsheet,
        ]);
    }
}
