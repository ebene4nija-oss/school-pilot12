<?php

namespace App\Console\Commands;

use App\Models\MarketingLead;
use App\Models\MarketingTouch;
use Illuminate\Console\Command;

/**
 * Deletes leads that never became schools, 12 months after last contact, as
 * the privacy notice promises (Landing PRD §12.4). Converted leads are kept:
 * they are now customer records under the school's contract.
 */
class PruneMarketingLeads extends Command
{
    protected $signature = 'marketing:prune-leads';

    protected $description = 'Delete unconverted marketing leads 12 months after last contact';

    public function handle(): int
    {
        $cutoff = now()->subMonths(12);

        $deleted = MarketingLead::query()
            ->whereNotIn('status', ['onboarding', 'live'])
            ->where(fn ($q) => $q
                ->where('last_contact_at', '<', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('last_contact_at')->where('created_at', '<', $cutoff)))
            ->delete();

        // Touches are anonymous, but they have no use after a year either.
        $touches = MarketingTouch::query()->where('updated_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} lead(s) and {$touches} touch(es).");

        return self::SUCCESS;
    }
}
