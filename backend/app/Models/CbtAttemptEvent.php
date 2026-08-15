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
        // Reported by the offline lab relay after the exam (§12). They
        // describe the room rather than the browser tab: a machine that died,
        // a candidate moved to a spare seat, a lab of PCs with wrong BIOS
        // dates. The existing `fullscreen_exit` and `paste` cover what the
        // design document calls `fullscreen_exited` and `paste_detected` —
        // one name per thing beats a synonym per client.
        'client_crashed',
        'seat_changed',
        'relay_restarted',
        'clock_skew_detected',
        'paper_section_reached',
    ];

    /** The subset a client is allowed to report. The rest are server-only. */
    public const CLIENT_REPORTABLE_TYPES = [
        'focus_lost', 'focus_regained', 'fullscreen_exit', 'paste', 'copy',
        'navigation_blocked', 'network_lost', 'network_restored',
    ];

    /**
     * What a relay may upload in a batch.
     *
     * Wider than the browser's set because the relay observed things a browser
     * cannot — but still not `manually_graded` or `offline_sync`, which are
     * the server's own account of what it did and must not be forgeable by a
     * laptop that was offline all morning.
     */
    public const RELAY_REPORTABLE_TYPES = [
        'focus_lost', 'focus_regained', 'fullscreen_exit', 'paste', 'copy',
        'navigation_blocked', 'network_lost', 'network_restored',
        'client_crashed', 'seat_changed', 'relay_restarted',
        'clock_skew_detected', 'paper_section_reached', 'resumed',
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
