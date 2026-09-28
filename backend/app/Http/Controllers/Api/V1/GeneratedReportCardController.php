<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportCardJob;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The PDFs a queued report-card run left behind.
 *
 * `report-cards/generate` writes one file per child and reports only counts.
 * An admin then needs to see which children are ready, which are not, and why
 * — almost always an AI remark a teacher has not yet approved — before they
 * release anything to parents.
 */
class GeneratedReportCardController extends Controller
{
    public function index(Request $request)
    {
        $schoolId = $this->schoolId();

        $validated = $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
        ]);

        $termId = (int) $validated['term_id'];

        $students = Student::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->when(! empty($validated['class_id']), fn ($q) => $q->where('class_id', $validated['class_id']))
            ->with('user:id,name')
            ->orderBy('admission_number')
            ->get(['id', 'user_id', 'class_id', 'admission_number']);

        $pending = ScoreEntry::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('term_id', $termId)
            ->where('ai_comment_status', 'pending_approval')
            ->whereIn('student_id', $students->pluck('id'))
            ->selectRaw('student_id, COUNT(*) as n')
            ->groupBy('student_id')
            ->pluck('n', 'student_id');

        $disk = Storage::disk('local');

        $rows = $students->map(function (Student $s) use ($schoolId, $termId, $disk, $pending) {
            $path = GenerateReportCardJob::pathFor($schoolId, $termId, $s->id);
            $ready = $disk->exists($path);

            return [
                'student_id' => $s->id,
                'name' => $s->user?->name,
                'admission_number' => $s->admission_number,
                'ready' => $ready,
                'generated_at' => $ready ? date(DATE_ATOM, $disk->lastModified($path)) : null,
                'pending_ai_comments' => (int) ($pending[$s->id] ?? 0),
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'total' => $rows->count(),
            'ready' => $rows->where('ready', true)->count(),
        ]);
    }

    public function download(Request $request, int $studentId, int $termId)
    {
        $schoolId = $this->schoolId();

        $owned = Student::withoutGlobalScopes()->where('school_id', $schoolId)->whereKey($studentId)->exists()
            && Term::withoutGlobalScopes()->where('school_id', $schoolId)->whereKey($termId)->exists();

        $path = GenerateReportCardJob::pathFor($schoolId, $termId, $studentId);

        if (! $owned || ! Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'That report card has not been generated.'], 404);
        }

        return Storage::disk('local')->download(
            $path,
            "report-card-{$studentId}-term-{$termId}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }
}
