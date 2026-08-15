<?php

namespace App\Console\Commands;

use App\Models\SchoolDataExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Delete expired data archives.
 *
 * Each one is a complete copy of a school's records — every child's name, class
 * and guardian, sometimes their medical notes. Once the download window has
 * passed it has no purpose, and NDPA data-minimisation (doc §12) says it should
 * not still be on disk.
 */
class SweepSchoolDataExports extends Command
{
    protected $signature = 'exports:sweep';

    protected $description = 'Delete school data export archives whose download window has passed';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $deleted = 0;

        SchoolDataExport::withoutGlobalScopes()
            ->whereNotNull('file_path')
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($exports) use ($disk, &$deleted) {
                foreach ($exports as $export) {
                    if ($disk->exists($export->file_path)) {
                        $disk->delete($export->file_path);
                    }

                    /*
                     * The row survives with its counts and its audit trail —
                     * "this school exported everything on 3 March" is worth
                     * keeping long after the file itself should be gone.
                     */
                    $export->update(['file_path' => null, 'status' => 'expired']);
                    $deleted++;
                }
            });

        $this->info("Swept {$deleted} expired export archive(s).");

        return self::SUCCESS;
    }
}
