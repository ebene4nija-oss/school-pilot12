<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One scheduled virtual lesson.
 *
 * SchoolPilot stores the joining details and the schedule; the video call
 * itself lives in whatever the school already pays for.
 */
class LiveClass extends Model
{
    use BelongsToTenant;

    protected $table = 'live_classes';

    protected $fillable = [
        'school_id',
        'class_id',
        'arm_id',
        'subject_id',
        'teacher_id',
        'title',
        'description',
        'provider',
        'meeting_url',
        'meeting_id',
        'passcode',
        'scheduled_for',
        'duration_minutes',
        'status',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_minutes' => 'integer',
    ];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function arm()
    {
        return $this->belongsTo(Arm::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    /** When the session is due to finish, from its start and length. */
    public function getEndsAtAttribute()
    {
        return $this->scheduled_for?->copy()->addMinutes($this->duration_minutes);
    }

    /**
     * Whether the join button should be live.
     *
     * Opens five minutes early, because a class that can only be joined at
     * exactly the scheduled minute starts late every time, and stays joinable
     * until the session is due to end.
     */
    public function getIsJoinableAttribute(): bool
    {
        if (! in_array($this->status, ['scheduled', 'live'], true)) {
            return false;
        }

        if (! $this->scheduled_for) {
            return false;
        }

        return now()->gte($this->scheduled_for->copy()->subMinutes(5))
            && now()->lte($this->ends_at);
    }
}
