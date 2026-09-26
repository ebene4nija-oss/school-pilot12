<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A notice from SchoolPilot to school administrators.
 *
 * Not `BelongsToTenant`: a row with a null `school_id` is addressed to every
 * school, which a tenant scope would hide from all of them.
 */
class PlatformAnnouncement extends Model
{
    protected $fillable = [
        'school_id',
        'title',
        'body',
        'tone',
        'shows_until',
        'created_by',
        'withdrawn_at',
    ];

    protected $casts = [
        'shows_until' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Still showing: not withdrawn and not past its end. */
    public function scopeShowing(Builder $query): Builder
    {
        return $query->whereNull('withdrawn_at')->where('shows_until', '>', now());
    }

    /** Addressed to this school, or to every school. */
    public function scopeFor(Builder $query, int $schoolId): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $schoolId));
    }
}
