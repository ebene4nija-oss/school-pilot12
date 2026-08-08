<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A discount or scholarship a school offers.
 *
 * One model covers both because they are the same arithmetic: a staff-child
 * discount and a merit scholarship both reduce an invoice by a percentage or a
 * fixed naira amount. Splitting them would duplicate the calculation.
 */
class Scholarship extends Model
{
    use BelongsToTenant;

    protected $table = 'scholarships';

    protected $fillable = ['school_id', 'name', 'type', 'value', 'description'];

    protected $casts = [
        'value' => 'decimal:2',
    ];

    /**
     * What this award takes off a given amount, in naira.
     *
     * Never returns more than the amount itself — a ₦60,000 fixed bursary
     * against a ₦50,000 invoice discounts ₦50,000, it does not leave the
     * school owing the family ₦10,000.
     */
    public function discountOn(float $amount): float
    {
        $discount = $this->type === 'percentage'
            ? $amount * ((float) $this->value / 100)
            : (float) $this->value;

        return round(min($discount, $amount), 2);
    }
}
