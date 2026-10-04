<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One month's payroll, versioned: a correction to an approved run is a new version. */
class PayrollRun extends Model
{
    use BelongsToTenant;

    protected $table = 'payroll_runs';

    protected $fillable = ['school_id', 'period', 'version', 'status', 'total_gross', 'total_deductions', 'total_net', 'staff_count', 'prepared_by', 'approved_by', 'approved_at', 'self_approval_reason', 'paid_at', 'payment_reference', 'supersedes_id', 'reopen_reason'];

    protected $casts = ['total_gross' => 'decimal:2', 'total_deductions' => 'decimal:2', 'total_net' => 'decimal:2', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];
}
