<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One staff member's pay for a payroll run. */
class Payslip extends Model
{
    use BelongsToTenant;

    protected $table = 'payslips';

    protected $fillable = ['school_id', 'run_id', 'staff_id', 'basic', 'earnings', 'deductions', 'gross', 'total_deductions', 'net', 'leave_days'];

    protected $casts = ['basic' => 'decimal:2', 'earnings' => 'array', 'deductions' => 'array', 'gross' => 'decimal:2', 'total_deductions' => 'decimal:2', 'net' => 'decimal:2'];
}
