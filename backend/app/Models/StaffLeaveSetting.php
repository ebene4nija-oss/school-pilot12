<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A school's staff leave policy (Settings > Staff leave).
 *
 * By default every staff role may request leave and no allowance is
 * enforced — the approver decides, as most schools have no formal policy.
 */
class StaffLeaveSetting extends Model
{
    use BelongsToTenant;

    /** Staff roles that can hold leave. School admins file their own too. */
    public const ROLES = ['teacher' => 'Teachers', 'bursar' => 'Bursars', 'nurse' => 'Nurses'];

    protected $fillable = ['school_id', 'leave_roles', 'default_allowances', 'enforce_allowance'];

    protected $casts = [
        'leave_roles' => 'array',
        'default_allowances' => 'array',
        'enforce_allowance' => 'boolean',
    ];

    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self([
                'school_id' => $schoolId,
                'leave_roles' => array_keys(self::ROLES),
                'default_allowances' => [],
                'enforce_allowance' => false,
            ]);
    }

    /** Whether staff in this role request leave through SchoolPilot. */
    public function allows(?string $role): bool
    {
        if (in_array($role, ['school_admin', 'super_admin'], true)) {
            return true;
        }

        return in_array($role, $this->leave_roles ?? array_keys(self::ROLES), true);
    }

    /** The school's default days for a leave type, or null if it sets none. */
    public function defaultFor(string $type): ?int
    {
        $days = ($this->default_allowances ?? [])[$type] ?? null;

        return is_numeric($days) ? (int) $days : null;
    }
}
