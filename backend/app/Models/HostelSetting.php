<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_settings';

    protected $fillable = [
        'school_id',
        'parents_see_bed',
        'parents_request_leave',
        'students_request_leave',
        'students_report_maintenance',
        'consent_leave_types',
        'return_grace_minutes',
    ];

    protected $casts = [
        'parents_see_bed' => 'boolean',
        'parents_request_leave' => 'boolean',
        'students_request_leave' => 'boolean',
        'students_report_maintenance' => 'boolean',
        'consent_leave_types' => 'array',
    ];

    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self(['school_id' => $schoolId, 'parents_see_bed' => true, 'parents_request_leave' => true, 'students_request_leave' => false, 'students_report_maintenance' => true, 'consent_leave_types' => ['weekend', 'outing', 'family'], 'return_grace_minutes' => 30]);
    }
}
