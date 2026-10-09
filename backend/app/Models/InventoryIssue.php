<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One hand-over of items to a student, confirmed by a parent.
 *
 * awaiting  — handed over, the parent hasn't answered
 * confirmed — the parent says everything arrived
 * disputed  — the parent says something is missing
 * resolved  — the school dealt with a dispute
 */
class InventoryIssue extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_issues';

    protected $fillable = ['school_id', 'student_id', 'term_id', 'pack_id', 'status', 'parent_note', 'confirmed_by', 'confirmed_at', 'resolution_note', 'issued_by'];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryIssueLine::class, 'issue_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
