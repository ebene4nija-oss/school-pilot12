<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A student's time in one virtual class, from SchoolPilot's class page.
 * It measures time with the lesson page open, not presence inside the
 * provider's meeting; a teacher corrects it, with a reason, when it is wrong.
 */
class LiveClassParticipant extends Model
{
    use BelongsToTenant;

    protected $table = 'live_class_participants';

    protected $fillable = [
        'school_id', 'live_class_id', 'student_id', 'first_joined_at', 'last_left_at', 'last_seen_at', 'connected',
        'seconds_connected', 'joins', 'corrected_status', 'corrected_by', 'correction_reason', 'corrected_at',
    ];

    protected $casts = [
        'first_joined_at' => 'datetime',
        'last_left_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'corrected_at' => 'datetime',
        'connected' => 'boolean',
        'seconds_connected' => 'integer',
        'joins' => 'integer',
    ];
}
