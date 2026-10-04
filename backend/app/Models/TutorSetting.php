<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** School control over the student AI tutor (TASK 15). */
class TutorSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'tutor_settings';

    protected $fillable = ['school_id', 'sections', 'subject_ids', 'daily_message_limit', 'exam_lock'];

    protected $casts = [
        'sections' => 'array',
        'subject_ids' => 'array',
        'daily_message_limit' => 'integer',
        'exam_lock' => 'boolean',
    ];

    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self(['school_id' => $schoolId, 'sections' => null, 'subject_ids' => null, 'daily_message_limit' => 60, 'exam_lock' => true]);
    }
}
