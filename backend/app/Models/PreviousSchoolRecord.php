<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Another school's record of a student, as received. Read-only: it is never
 * merged into this school's own records, and a local correction is a new row
 * that amends it, keeping both values.
 */
class PreviousSchoolRecord extends Model
{
    use BelongsToTenant;

    private const MUTABLE = ['verification_status', 'verified_by', 'verified_at', 'provenance', 'student_id', 'updated_at'];

    protected $fillable = [
        'school_id', 'student_id', 'transfer_id', 'source_school_name', 'source_school_id', 'source_package_hash',
        'provenance', 'verification_status', 'data', 'data_hash', 'amends_id', 'amendment_reason',
        'imported_by', 'verified_by', 'verified_at',
    ];

    protected $casts = ['verified_at' => 'datetime'];

    protected $hidden = ['data'];

    protected static function booted(): void
    {
        static::updating(function (PreviousSchoolRecord $record) {
            if (array_diff(array_keys($record->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException("Another school's record cannot be edited. Record an amendment instead.");
            }
        });

        static::deleting(fn () => throw new LogicException('Previous-school records are never deleted.'));
    }

    public function content(): array
    {
        return json_decode($this->data, true) ?? [];
    }
}
