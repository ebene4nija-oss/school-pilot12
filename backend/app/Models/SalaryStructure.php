<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A staff member's pay from a date. A change is a new row; history stays. */
class SalaryStructure extends Model
{
    use BelongsToTenant;

    protected $table = 'salary_structures';

    protected $fillable = ['school_id', 'staff_id', 'basic_monthly', 'components', 'effective_from', 'note', 'set_by'];

    protected $casts = ['basic_monthly' => 'decimal:2', 'components' => 'array', 'effective_from' => 'date'];
}
