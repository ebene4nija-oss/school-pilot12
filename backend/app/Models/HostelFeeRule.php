<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelFeeRule extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_fee_rules';

    protected $fillable = [
        'school_id',
        'boarding_category',
        'charge_type',
        'title',
        'amount',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];
}
