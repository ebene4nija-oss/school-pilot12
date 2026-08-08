<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One notification to one recipient.
 *
 * Deliberately one job per recipient rather than one job for the whole
 * broadcast. A school messaging 900 families over SMS is 900 network calls;
 * as a single job it is one thing that fails at recipient 400 and either
 * re-sends the first 399 on retry or drops the last 500. Per-recipient, a
 * failure is one family's message, retried on its own.
 */
class SendNotificationJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Gateways rate-limit and wobble; back off rather than hammering. */
    public array $backoff = [10, 60, 180];

    public function __construct(
        private int $userId,
        private string $body,
        private array $channels,
        private array $context = []
    ) {
    }

    public function handle(NotificationService $notifications): void
    {
        // Cancelled batch: the admin stopped a broadcast that was mid-flight.
        if ($this->batch()?->cancelled()) {
            return;
        }

        $user = User::with('userProfile')->find($this->userId);

        if (! $user) {
            // The account was deleted between queueing and sending. Nothing to
            // retry — failing here would just churn the queue.
            return;
        }

        $notifications->notify($user, $this->body, $this->channels, $this->context);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("Notification to user {$this->userId} failed permanently: " . $e->getMessage());
    }
}
