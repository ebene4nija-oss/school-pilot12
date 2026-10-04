<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A school's transfer policy. Every default is the spec's recommended one. */
class TransferSetting extends Model
{
    use BelongsToTenant;

    public const DEFAULT_STEPS = ['registrar', 'principal', 'admin_release'];

    public const STEP_LABELS = [
        'registrar' => 'Registrar review',
        'principal' => 'Principal approval',
        'admin_release' => 'Admin release',
    ];

    public const DEFAULT_CLEARANCE = ['fees', 'library', 'books', 'property'];

    protected $fillable = [
        'school_id', 'approval_steps', 'fees_block_release', 'package_expiry_days', 'code_expiry_days',
        'lawful_basis', 'students_may_request', 'end_student_access_on_completion', 'clearance_items',
        'applicant_retention_days',
    ];

    protected $casts = [
        'approval_steps' => 'array',
        'clearance_items' => 'array',
        'fees_block_release' => 'boolean',
        'students_may_request' => 'boolean',
        'end_student_access_on_completion' => 'boolean',
    ];

    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self([
                'school_id' => $schoolId,
                'approval_steps' => self::DEFAULT_STEPS,
                'fees_block_release' => false,
                'package_expiry_days' => 14,
                'code_expiry_days' => 14,
                'lawful_basis' => 'guardian_consent',
                'students_may_request' => false,
                'end_student_access_on_completion' => true,
                'clearance_items' => self::DEFAULT_CLEARANCE,
                'applicant_retention_days' => 365,
            ]);
    }

    public function steps(): array
    {
        return $this->approval_steps ?: self::DEFAULT_STEPS;
    }

    public function clearance(): array
    {
        return $this->clearance_items ?: self::DEFAULT_CLEARANCE;
    }
}
