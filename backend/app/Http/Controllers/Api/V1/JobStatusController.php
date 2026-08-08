<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportCardJob;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\Rule;

/**
 * Long-running work, and how a client finds out whether it finished.
 *
 * Everything that used to run inline in a request — whole-school report cards,
 * whole-school broadcasts — now returns a batch id immediately and is polled
 * here. Without a status endpoint, queueing just converts a visible timeout
 * into a silent one.
 */
class JobStatusController extends Controller
{
    private function schoolId(Request $request): ?int
    {
        return $request->user()?->userProfile?->school_id;
    }

    /**
     * Progress of a queued batch.
     *
     * Batch ids are UUIDs, so they are not enumerable, but the batch name
     * carries the school id and is checked — a bursar at School A polling a
     * guessed id must not learn how School B's term-end run is going.
     */
    public function show(Request $request, string $batchId)
    {
        $batch = Bus::findBatch($batchId);

        if (! $batch) {
            return response()->json(['error' => 'No such job batch.'], 404);
        }

        $schoolId = $this->schoolId($request);

        if ($schoolId && ! str_ends_with($batch->name, ":school:{$schoolId}")) {
            return response()->json(['error' => 'No such job batch.'], 404);
        }

        return response()->json([
            'batch_id' => $batch->id,
            'total' => $batch->totalJobs,
            'pending' => $batch->pendingJobs,
            'processed' => $batch->processedJobs(),
            'failed' => $batch->failedJobs,
            'progress_percentage' => $batch->progress(),
            'finished' => $batch->finished(),
            'cancelled' => $batch->cancelled(),
            'created_at' => $batch->createdAt?->toIso8601String(),
            'finished_at' => $batch->finishedAt?->toIso8601String(),
        ]);
    }

    /** Stop a run that was started by mistake — 900 texts is real money. */
    public function cancel(Request $request, string $batchId)
    {
        $batch = Bus::findBatch($batchId);
        $schoolId = $this->schoolId($request);

        if (! $batch || ($schoolId && ! str_ends_with($batch->name, ":school:{$schoolId}"))) {
            return response()->json(['error' => 'No such job batch.'], 404);
        }

        $batch->cancel();

        return response()->json(['message' => 'Batch cancelled. Jobs already in flight will finish.']);
    }

    /**
     * Queue a whole class's report cards.
     *
     * This is the term-end case the synchronous path cannot survive: 800
     * students × a dompdf render is not a 60-second request.
     */
    public function generateReportCards(Request $request)
    {
        $schoolId = $this->schoolId($request);

        $validated = $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
        ]);

        $students = Student::where('school_id', $schoolId)
            ->where('status', 'active')
            ->when(! empty($validated['class_id']), fn ($q) => $q->where('class_id', $validated['class_id']))
            ->pluck('id');

        if ($students->isEmpty()) {
            return response()->json(['error' => 'No active students match that class.'], 422);
        }

        $batch = Bus::batch(
            $students->map(fn ($studentId) => new GenerateReportCardJob(
                $schoolId,
                (int) $studentId,
                (int) $validated['term_id']
            ))->all()
        )
            // The school id rides in the name so `show()` can scope access
            // without a second table.
            ->name("report-cards:school:{$schoolId}")
            // One child whose AI remark is still pending must not abandon the
            // other 799 cards.
            ->allowFailures()
            ->dispatch();

        return response()->json([
            'message' => "Generating {$students->count()} report card(s).",
            'batch_id' => $batch->id,
            'status_url' => "/api/v1/jobs/{$batch->id}",
        ], 202);
    }
}
