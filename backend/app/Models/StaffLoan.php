<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A loan or salary advance repaid through payroll. */
class StaffLoan extends Model
{
    use BelongsToTenant;

    protected $table = 'staff_loans';

    protected $fillable = ['school_id', 'staff_id', 'kind', 'principal', 'monthly_deduction', 'balance', 'status', 'approved_by', 'note'];

    protected $casts = ['principal' => 'decimal:2', 'monthly_deduction' => 'decimal:2', 'balance' => 'decimal:2'];
}
