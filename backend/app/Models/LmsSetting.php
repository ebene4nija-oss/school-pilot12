<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** LMS policy for one school (TASK 14). Field meanings are in the LMS migration. */
class LmsSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'lms_settings';

    protected $fillable = [
        'school_id',
        'require_material_approval',
        'parents_see_materials',
        'parent_assist_sections',
        'present_percent',
        'late_grace_minutes',
        'recording_allowed',
        'recording_notice',
        'max_upload_mb',
    ];

    protected $casts = [
        'require_material_approval' => 'boolean',
        'parents_see_materials' => 'boolean',
        'parent_assist_sections' => 'array',
        'present_percent' => 'integer',
        'late_grace_minutes' => 'integer',
        'recording_allowed' => 'boolean',
        'max_upload_mb' => 'integer',
    ];

    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self(['school_id' => $schoolId, 'require_material_approval' => false, 'parents_see_materials' => true, 'parent_assist_sections' => ['nursery'], 'present_percent' => 75, 'late_grace_minutes' => 10, 'recording_allowed' => false, 'recording_notice' => null, 'max_upload_mb' => 50]);
    }
}
