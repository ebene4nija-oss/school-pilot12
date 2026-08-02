<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'name',
        'company_name',
        'phone',
        'email',
        'bank_name',
        'account_number',
    ];

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}
