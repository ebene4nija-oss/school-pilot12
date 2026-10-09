<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\School;
use App\Models\Student;
use App\Support\InAppNotifier;
use App\Support\SchoolFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Releasing one CBT paper's results to its candidates and their parents.
 *
 * For a paper that did not show the score on submit, the teacher who set it
 * (or an admin) releases the scores when they are ready, typically after
 * marking the theory. This is the paper's score only: the term's report
 * cards keep their own release (ResultRelease), untouched by this.
 */
class CbtResultReleaseController extends Controller
{
    public function release(Request $request, int $id): JsonResponse
    {
        $exam = CbtExam::where('school_id', $this->schoolId())->findOrFail($id);
        $this->authorize('manage', $exam);
        $request->validate(['include_unmarked' => 'sometimes|boolean']);

        $finalised = $this->finalised($exam);

        if ($finalised->isEmpty()) {
            return response()->json(['message' => 'Nobody has submitted this paper yet, so there is nothing to release.'], 409);
        }

        $unmarked = $finalised->where('requires_manual_grading', true)->count();

        // Releasing before the theory is marked shows candidates a score
        // that will still change. Allowed, but only on purpose.
        if ($unmarked > 0 && ! $request->boolean('include_unmarked')) {
            return response()->json([
                'message' => "{$unmarked} script(s) still have theory answers to mark. Mark them first, or release anyway and their scores will update as you mark.",
                'code' => 'unmarked_scripts',
                'unmarked' => $unmarked,
            ], 409);
        }

        $first = $exam->results_released_at === null;
        $exam->update(['results_released_at' => now(), 'results_released_by' => $request->user()->id]);
        $this->audit($request, $exam, 'cbt.results_released', ['candidates' => $finalised->count(), 'unmarked' => $unmarked]);

        if ($first) {
            $studentIds = $finalised->pluck('student_id')->unique()->values();
            $students = Student::whereIn('id', $studentIds)->pluck('user_id')->filter()->all();
            $guardians = DB::table('student_guardian')->join('guardians', 'guardians.id', '=', 'student_guardian.guardian_id')
                ->whereIn('student_guardian.student_id', $studentIds)->pluck('guardians.user_id')->filter()->all();

            InAppNotifier::send($exam->school_id, $students, 'cbt', 'CBT result released', "Your result for {$exam->title} is ready to view.", true);
            InAppNotifier::send($exam->school_id, $guardians, 'cbt', 'CBT result released', "Your child's result for {$exam->title} is ready to view.", true);
        }

        return response()->json([
            'message' => "Results released to {$finalised->count()} student(s) and their parents.",
            'results_released_at' => $exam->results_released_at->toIso8601String(),
            'candidates' => $finalised->count(),
        ]);
    }

    /** Take a release back, e.g. a marking mistake found after release. */
    public function withdraw(Request $request, int $id): JsonResponse
    {
        $exam = CbtExam::where('school_id', $this->schoolId())->findOrFail($id);
        $this->authorize('manage', $exam);

        $exam->update(['results_released_at' => null, 'results_released_by' => null]);
        $this->audit($request, $exam, 'cbt.results_withdrawn', []);

        return response()->json([
            'message' => $exam->show_results_immediately
                ? 'Release withdrawn. This paper still shows the score on submit; turn that off in its settings to hide scores.'
                : 'Release withdrawn. Students and parents no longer see the scores.',
        ]);
    }

    /** A parent's view of one child's CBT scores, on released papers only. */
    public function child(Request $request, int $studentId): JsonResponse
    {
        $student = Student::where('school_id', $this->schoolId())->findOrFail($studentId);
        abort_unless($student->isGuardedBy($request->user()), 404);

        if (! SchoolFeatures::moduleEnabled(School::find($student->school_id), 'cbt')) {
            return response()->json(['data' => []]);
        }

        $attempts = CbtAttempt::where('student_id', $student->id)->whereIn('status', ['submitted', 'graded', 'expired'])
            ->orderByDesc('submitted_at')->limit(100)->get();
        $exams = CbtExam::whereIn('id', $attempts->pluck('exam_id')->unique())->with('subject:id,name')->get()->keyBy('id');

        // Each paper once, at its best finalised attempt.
        $rows = $attempts->groupBy('exam_id')->map(function ($group, $examId) use ($exams) {
            $exam = $exams->get($examId);
            if (! $exam || ! $exam->resultsVisible()) {
                return null;
            }
            $best = $group->sortByDesc(fn ($a) => (float) $a->percentage)->first();

            return [
                'exam_id' => $exam->id,
                'title' => $exam->title,
                'subject' => $exam->subject?->name,
                'submitted_at' => $best->submitted_at?->toIso8601String(),
                'raw_score' => (float) $best->raw_score,
                'max_score' => (float) $best->max_score,
                'percentage' => (float) $best->percentage,
                'grade' => $best->grade,
                'passed' => (float) $best->percentage >= (float) $exam->pass_mark,
                // Theory still to be marked: the score may still rise.
                'provisional' => (bool) $best->requires_manual_grading,
            ];
        })->filter()->values();

        return response()->json(['data' => $rows]);
    }

    private function finalised(CbtExam $exam)
    {
        return CbtAttempt::where('exam_id', $exam->id)->whereIn('status', ['submitted', 'graded', 'expired'])->get(['id', 'student_id', 'requires_manual_grading']);
    }

    private function audit(Request $request, CbtExam $exam, string $action, array $values): void
    {
        AuditLog::create([
            'school_id' => $exam->school_id, 'user_id' => $request->user()->id, 'action' => $action,
            'auditable_type' => CbtExam::class, 'auditable_id' => $exam->id, 'new_values' => $values,
        ]);
    }
}
