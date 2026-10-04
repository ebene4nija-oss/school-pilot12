<?php

namespace App\Support;

use App\Models\NotificationLog;
use App\Models\User;
use App\Services\NotificationService;

/**
 * A notification into a user's SchoolPilot inbox, with a push attempt where
 * they have a device.
 *
 * In-app first (the TASK specs agree on this), with SMS and WhatsApp left to
 * the school's own audience tools. Bodies carry what happened and where to
 * look, never academic or health content — a lock-screen preview is not a
 * private channel.
 *
 * Never throws: a notification failing must not undo the action it reports.
 */
class InAppNotifier
{
    /**
     * `$push` also sends to the user's phones. Off by default: a delivered
     * push writes its own inbox row, so pushing would list the message twice.
     *
     * @param  array<int, int>  $userIds
     */
    public static function send(int $schoolId, array $userIds, string $category, string $title, string $body, bool $push = false): void
    {
        foreach (array_unique(array_filter($userIds)) as $userId) {
            try {
                NotificationLog::withoutGlobalScopes()->create([
                    'school_id' => $schoolId,
                    'user_id' => $userId,
                    'channel' => 'in_app',
                    'category' => $category,
                    'body' => "{$title}: {$body}",
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                if ($push && ($user = User::find($userId))) {
                    app(NotificationService::class)->push($user, $body, ['title' => $title, 'category' => $category]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
