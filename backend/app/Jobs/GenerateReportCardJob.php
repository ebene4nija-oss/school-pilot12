<?php

namespace App\Jobs;

use App\Services\ReportCardPdfService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Render one child's report card to a stored PDF.
 *
 * The single-card endpoint stays synchronous — a teacher clicking one card
 * should get a PDF back, not a job id. This exists for the other case: the day
 * the whole school's cards are produced, which is 800 students × a dompdf
 * render inside one request on shared hosting with a 60s timeout.
 *
 * The pending_approval guardrail still holds: ReportCardPdfService refuses to
 * render a card carrying an unreviewed AI remark, and that refusal surfaces
 * here as a failed job rather than a silently skipped child.
 */
class GenerateReportCardJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        private int $schoolId,
        private int $studentId,
        private int $termId
    ) {
    }

    public function handle(ReportCardPdfService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $pdf = $service->renderPdf($this->studentId, $this->termId);

        // Tenant-prefixed so one school's cards can never be served from
        // another's path, and so a term's output can be swept in one go.
        Storage::disk('local')->put($this->path(), $pdf);
    }

    public function path(): string
    {
        return sprintf(
            'report-cards/%d/term-%d/student-%d.pdf',
            $this->schoolId,
            $this->termId,
            $this->studentId
        );
    }

    public function failed(\Throwable $e): void
    {
        // Most common cause by far is an AI comment still sitting in
        // pending_approval — worth naming, because the fix is a teacher
        // approving it, not a retry.
        Log::error(sprintf(
            'Report card generation failed for student %d, term %d: %s',
            $this->studentId,
            $this->termId,
            $e->getMessage()
        ));
    }
}
