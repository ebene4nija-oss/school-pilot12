<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A change to an approved result. It waits as `pending` and touches the score
 * only when approved, so a published result never changes silently.
 */
class BroadsheetCorrection extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'broadsheet_id',
        'student_id',
        'subject_id',
        'score_entry_id',
        'old_values',
        'new_values',
        'reason',
        'status',
        'requested_by',
        'decided_by',
        'decided_at',
        'decision_note',
        'applied_in_version',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'decided_at' => 'datetime',
    ];
}
