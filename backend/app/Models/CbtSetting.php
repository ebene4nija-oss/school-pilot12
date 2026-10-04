<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A school's CBT policy: approval, preparation time, the live monitor. */
class CbtSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'exam_approval_required', 'trusted_teacher_ids', 'min_preparation_days', 'disconnect_after_seconds'];

    protected $casts = ['exam_approval_required' => 'boolean', 'trusted_teacher_ids' => 'array'];

    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self(['school_id' => $schoolId, 'exam_approval_required' => false, 'trusted_teacher_ids' => [], 'min_preparation_days' => 0, 'disconnect_after_seconds' => 120]);
    }
}
