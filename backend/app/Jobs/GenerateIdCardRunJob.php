<?php

namespace App\Jobs;

use App\Models\IdCardPrintRun;
use App\Services\IdCard\IdCardPrintService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Render one print run.
 *
 * One job for the whole run, not one per card — unlike report cards, which are
 * independent documents and fan out. Imposition is the opposite shape: a sheet
 * cannot be laid out until every card on it exists, and the duplex ordering is
 * a property of the run as a whole. Splitting it per card would mean
 * assembling the sheets in a completion callback, which is the same work with
 * an extra failure mode.
 *
 * The timeout is generous because it has to be: 400 cards is 400 photo reads,
 * 400 QR encodes and one large dompdf pass.
 */
class GenerateIdCardRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One attempt.
     *
     * A retry is actively harmful here. Rendering issues cards as a side
     * effect and increments print counts; a run that fails halfway and retries
     * would walk over its own half-finished work. A failed run is re-queued by
     * an admin, deliberately, after they have seen why it failed.
     */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(private int $runId)
    {
    }

    public function handle(IdCardPrintService $service, TenantContext $tenant): void
    {
        $run = IdCardPrintRun::withoutGlobalScopes()->find($this->runId);

        if (!$run || $run->status !== 'queued') {
            return;
        }

        // A worker has no authenticated user; without this the run's queries
        // would reach across every school on the platform.
        $tenant->forSchool($run->school_id, function () use ($service, $run) {
            $service->render($run);
        });
    }

    public function failed(\Throwable $e): void
    {
        $run = IdCardPrintRun::withoutGlobalScopes()->find($this->runId);

        // Without this the run sits at "rendering" for ever and the admin is
        // left refreshing a page that will never change.
        $run?->update([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'completed_at' => now(),
        ]);

        Log::error(sprintf('ID card print run %d failed: %s', $this->runId, $e->getMessage()));
    }
}
