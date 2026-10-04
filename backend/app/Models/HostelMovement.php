<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A timestamped arrival, departure, return or checkout. Never edited: a
 * correction is a new row that points at the one it corrects, with a reason
 * (TASK 17 §11, rule 17).
 */
class HostelMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'hostel_movements';

    protected $fillable = [
        'school_id', 'student_id', 'type', 'occurred_at', 'leave_request_id', 'collected_by', 'checklist',
        'ends_stay', 'notes', 'corrects_id', 'correction_reason', 'recorded_by',
    ];

    protected $casts = ['occurred_at' => 'datetime', 'checklist' => 'array', 'ends_stay' => 'boolean'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Movement events are never edited. Record a correction.'));
        static::deleting(fn () => throw new LogicException('Movement events are never deleted.'));
    }
}
