<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Who holds a head position, at the level-group level (decision D3).
 *
 * Ending an assignment sets `ended_at`; rows are never deleted, so who
 * approved what under which head stays answerable.
 */
class SectionHeadAssignment extends Model
{
    use BelongsToTenant;

    public const POSITIONS = ['principal', 'level_head', 'hod'];

    protected $fillable = [
        'school_id',
        'position',
        'level_group',
        'department',
        'user_id',
        'assigned_by',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeCurrent($query)
    {
        return $query->whereNull('ended_at');
    }
}
