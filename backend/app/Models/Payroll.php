<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'staff_id',
        'month_year',
        'basic_salary',
        'allowances',
        'deductions',
        'net_salary',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
