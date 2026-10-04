<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The school's numbers in the metrics dictionary: what counts as a decline,
 * low attendance, a strong topic. Defaults are the specs' examples, not
 * educational standards, and every one is the school's to change.
 */
class ProgressSetting extends Model
{
    use BelongsToTenant;

    public const DEFAULTS = [
        'trend_points' => 5,
        'steady_range' => 5,
        'sharp_decline_points' => 5,
        'low_attendance_rate' => 75,
        'low_completion_rate' => 80,
        'multiple_failed_subjects' => 3,
        'topic_strong' => 80,
        'topic_developing' => 60,
        'topic_min_questions' => 5,
        'core_subject_ids' => null,
        'max_flags_shown' => 5,
        'teachers_see_flags' => true,
        'students_see_position' => false,
        'students_see_interventions' => false,
    ];

    protected $fillable = [
        'school_id', 'trend_points', 'steady_range', 'sharp_decline_points', 'low_attendance_rate', 'low_completion_rate',
        'multiple_failed_subjects', 'topic_strong', 'topic_developing', 'topic_min_questions', 'core_subject_ids',
        'max_flags_shown', 'teachers_see_flags', 'students_see_position', 'students_see_interventions',
    ];

    protected $casts = [
        'core_subject_ids' => 'array',
        'teachers_see_flags' => 'boolean',
        'students_see_position' => 'boolean',
        'students_see_interventions' => 'boolean',
    ];

    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self(['school_id' => $schoolId] + self::DEFAULTS);
    }
}
