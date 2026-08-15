<?php

namespace App\Jobs;

use App\Models\SchoolDataExport;
use App\Services\SchoolDataExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds the archive off the request cycle.
 *
 * A school with nine years of scores takes far longer than a request should
 * live, which is why the old inline version could only ever have worked for a
 * demo tenant.
 */
class GenerateSchoolDataExportJob implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt. A retry would start a second archive over the first, and
     * whatever made the first fail — a disk full, a corrupt row — is not
     * usually fixed by trying again a minute later. The failure is recorded
     * on the export row for the admin to see and re-request.
     */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $exportId)
    {
    }

    public function handle(SchoolDataExportService $exports): void
    {
        $export = SchoolDataExport::withoutGlobalScopes()->find($this->exportId);

        if (! $export || $export->status === 'complete') {
            return;
        }

        $exports->generate($export);
    }

    public function failed(\Throwable $e): void
    {
        SchoolDataExport::withoutGlobalScopes()
            ->where('id', $this->exportId)
            ->where('status', '!=', 'complete')
            ->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
    }
}
