<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A school's choices for its student list. Defaults are the safe ones (spec §2, §9). */
class StudentDirectorySetting extends Model
{
    use BelongsToTenant;

    /** Fields a school may require for a record to count as complete. */
    public const FIELDS = [
        'date_of_birth' => 'Date of birth',
        'gender' => 'Gender',
        'class_id' => 'Class',
        'arm_id' => 'Arm',
        'guardian' => 'At least one guardian',
        'guardian_phone' => 'A guardian phone number',
        'passport_photo_path' => 'Photograph',
        'state_of_origin' => 'State of origin',
        'lga' => 'LGA',
    ];

    public const DEFAULT_MANDATORY = ['date_of_birth', 'gender', 'class_id', 'guardian', 'guardian_phone'];

    protected $fillable = ['school_id', 'mandatory_fields', 'name_order', 'subject_teachers_see_guardian_phone', 'show_gender_counts'];

    protected $casts = [
        'mandatory_fields' => 'array',
        'subject_teachers_see_guardian_phone' => 'boolean',
        'show_gender_counts' => 'boolean',
    ];

    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self([
                'school_id' => $schoolId,
                'mandatory_fields' => self::DEFAULT_MANDATORY,
                'name_order' => 'given_first',
                'subject_teachers_see_guardian_phone' => false,
                'show_gender_counts' => true,
            ]);
    }

    public function mandatory(): array
    {
        return $this->mandatory_fields ?: self::DEFAULT_MANDATORY;
    }
}
