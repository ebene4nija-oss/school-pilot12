<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One change to an important student field. Append-only. */
class StudentRecordChange extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /** The fields whose changes are versioned (spec §3 rule 5). */
    public const TRACKED = ['class_id', 'arm_id', 'status', 'date_of_birth', 'admission_number', 'gender'];

    protected $fillable = ['school_id', 'student_id', 'field', 'old_value', 'new_value', 'reason', 'changed_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Record changes are append-only.'));
        static::deleting(fn () => throw new LogicException('Record changes are append-only.'));
    }

    /** The reason for the change in progress, set by the code making it. */
    private static ?string $pendingReason = null;

    /** Run a change with a reason attached to every field it touches. */
    public static function because(?string $reason, callable $change): mixed
    {
        $previous = self::$pendingReason;
        self::$pendingReason = $reason;

        try {
            return $change();
        } finally {
            self::$pendingReason = $previous;
        }
    }

    public static function currentReason(): ?string
    {
        return self::$pendingReason;
    }
}
