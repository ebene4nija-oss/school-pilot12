<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An earning or deduction line a school defines (TASK 20). Statutory rates are the school's to set. */
class PayComponent extends Model
{
    use BelongsToTenant;

    protected $table = 'pay_components';

    protected $fillable = ['school_id', 'name', 'kind', 'calc', 'default_value', 'statutory', 'is_active'];

    protected $casts = ['default_value' => 'decimal:2', 'statutory' => 'boolean', 'is_active' => 'boolean'];
}
