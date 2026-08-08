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
    ];

    protected $casts = [
        'qualifications' => 'array',
        'employment_history' => 'array',
        'leave_allocations' => 'array',
        'employment_date' => 'date',
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
     * Days allowed for a leave type this year, from the school's allocation.
     *
     * Nigerian private schools vary widely here and many have no formal policy
     * at all, so an unconfigured type means "no allowance recorded" rather than
     * a made-up default — the approver decides.
     */
    public function allowanceFor(string $type): ?int
    {
        $allocations = $this->leave_allocations ?? [];

        return isset($allocations[$type]) ? (int) $allocations[$type] : null;
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
