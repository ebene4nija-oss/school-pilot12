<?php

namespace App\Console\Commands;

use App\Models\IdCardPrintRun;
use App\Services\IdCard\IdCardIssuanceService;
use App\Services\IdCard\IdCardPrintService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Delete expired print-run PDFs, and write down expiry that has already
 * happened.
 *
 * Two unrelated jobs, in one command, because they are the same daily
 * housekeeping pass and neither is worth a scheduler entry of its own.
 *
 * The deletion half is the one that matters. A print run's PDF is a page of
 * children's faces and full names — the most sensitive artefact this product
 * writes to disk. Keeping it past the week it is printed in is collection with
 * no remaining purpose, which is exactly what NDPA data minimisation (doc §12)
 * asks us not to do. The issuance rows are the permanent record; the sheets
 * are not, and a school that needs them again queues the run again.
 */
class SweepIdCardArtefacts extends Command
{
    protected $signature = 'id-cards:sweep {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete expired ID card print-run PDFs and mark lapsed cards expired';

    public function handle(IdCardPrintService $printer, IdCardIssuanceService $issuance): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk($printer->disk());

        $runs = IdCardPrintRun::withoutGlobalScopes()
            ->whereNotNull('pdf_path')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        $deleted = 0;

        foreach ($runs as $run) {
            if ($dryRun) {
                $this->line("would delete run {$run->id}: {$run->pdf_path}");
                $deleted++;
                continue;
            }

            if ($disk->exists($run->pdf_path)) {
                $disk->delete($run->pdf_path);
            }

            /*
             * The run row stays. It records that a class was printed, when,
             * by whom, and against which design — which is the audit trail a
             * school needs. Only the file goes.
             */
            $run->update(['pdf_path' => null, 'pdf_bytes' => null]);
            $deleted++;
        }

        $expired = $dryRun ? 0 : $issuance->expireStale();

        $this->info(sprintf(
            '%s %d expired print-run PDF(s); marked %d card(s) expired.',
            $dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            $expired
        ));

        return self::SUCCESS;
    }
}
