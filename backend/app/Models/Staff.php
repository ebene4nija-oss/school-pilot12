<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A member of staff.
 *
 * The table has existed since the first migration and was reached only through
 * raw `DB::table('staff')` calls; there was no model. `qualifications`,
 * `employment_history` and `leave_allocations` were added by a later migration
 * and never read by anything.
 */
class Staff extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'staff';

    protected $fillable = [
        'school_id',
        'user_id',
        'staff_id',
        'designation',
        'qualification',
        'qualifications',
        'salary',
        'leave_allocations',
        'employment_date',
        'employment_history',
        'employment_status',
        'employment_type',
        'department',
        'separated_on',
        'separation_reason',
    ];

    protected $casts = [
        'qualifications' => 'array',
        'employment_history' => 'array',
        'leave_allocations' => 'array',
        'employment_date' => 'date',
        'separated_on' => 'date',
        'salary' => 'decimal:2',
    ];

    protected $hidden = [
        // Salary is not something a colleague's profile lookup should return.
        // Payroll reads the column directly.
        'salary',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * Days allowed for a leave type this year: this member's own allocation,
     * else the school's default (Settings > Staff leave).
     *
     * Nigerian private schools vary widely here and many have no formal policy
     * at all, so an unconfigured type means "no allowance recorded" rather than
     * a made-up default — the approver decides.
     */
    public function allowanceFor(string $type): ?int
    {
        $allocations = $this->leave_allocations ?? [];

        if (isset($allocations[$type])) {
            return (int) $allocations[$type];
        }

        // Otherwise the school's default for that type, if it sets one.
        return StaffLeaveSetting::for((int) $this->school_id)->defaultFor($type);
    }

    /** Days already approved for a type in the current calendar year. */
    public function daysTakenThisYear(string $type): int
    {
        return (int) $this->leaveRequests()
            ->where('leave_type', $type)
            ->where('status', 'approved')
            ->whereYear('start_date', now()->year)
            ->sum('days_requested');
    }
}
