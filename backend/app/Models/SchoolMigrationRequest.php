<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One school's request to have its old records brought across.
 */
class SchoolMigrationRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'requested_by',
        'previous_system',
        'source_format',
        'notes',
        'approximate_records',
        'status',
        'due_at',
        'completed_at',
        'operator_notes',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'approximate_records' => 'integer',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Past its promised date and still not done. */
    public function getIsOverdueAttribute(): bool
    {
        if (in_array($this->status, ['completed', 'cancelled'], true)) {
            return false;
        }

        return $this->due_at !== null && $this->due_at->isPast();
    }
}
