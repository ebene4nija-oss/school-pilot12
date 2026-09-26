<?php

namespace App\Jobs;

use App\Services\AbsenceAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Absence alerts for one register submission, off the request: a teacher
 * saving a forty-child register should not wait on an SMS gateway.
 */
class SendAbsenceAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A gateway outage should not retry into a second message. */
    public int $tries = 1;

    /**
     * @param  array<int,int>  $studentIds
     */
    public function __construct(
        public int $schoolId,
        public array $studentIds,
        public string $date,
    ) {
    }

    public function handle(AbsenceAlertService $alerts): void
    {
        $alerts->send($this->schoolId, $this->studentIds, $this->date);
    }
}
