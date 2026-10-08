<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A payment made to a partner, recorded by hand. Platform-level: no tenant scope. */
class PartnerPayout extends Model
{
    protected $fillable = ['partner_id', 'amount_kobo', 'paid_on', 'reference', 'note', 'recorded_by'];

    protected $casts = [
        'amount_kobo' => 'integer',
        'paid_on' => 'date',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function commissions()
    {
        return $this->hasMany(PartnerCommission::class, 'payout_id');
    }
}
