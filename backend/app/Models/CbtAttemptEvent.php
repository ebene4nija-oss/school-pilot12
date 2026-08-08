<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CbtAttemptEvent extends Model
{
    use HasFactory;

    protected $table = 'cbt_attempt_events';

    /**
     * Hardware-free invigilation signals. These are observations, not
     * verdicts — a teacher reviews flagged attempts and decides.
     */
    public const TYPES = [
        // Reported by the client while a candidate is sitting the paper.
        'focus_lost',
        'focus_regained',
        'fullscreen_exit',
        'paste',
        'copy',
        'navigation_blocked',
        'network_lost',
        'network_restored',
        // Recorded by the server as the attempt moves through its lifecycle.
        'offline_sync',
        'reconnected',
        'resumed',
        'auto_submit',
        'manual_submit',
        'manually_graded',
    ];

    /** The subset a client is allowed to report. The rest are server-only. */
    public const CLIENT_REPORTABLE_TYPES = [
        'focus_lost', 'focus_regained', 'fullscreen_exit', 'paste', 'copy',
        'navigation_blocked', 'network_lost', 'network_restored',
    ];

    protected $fillable = [
        'attempt_id',
        'event_type',
        'occurred_at',
        'metadata',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function attempt()
    {
        return $this->belongsTo(CbtAttempt::class, 'attempt_id');
    }
}
