<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What one paid term bill earned one partner, at the rate in force when the
 * bill was paid. Platform-level: no tenant scope.
 */
class PartnerCommission extends Model
{
    protected $fillable = ['partner_id', 'school_id', 'term_bill_id', 'rate_bps', 'amount_kobo', 'status', 'payout_id'];

    protected $casts = [
        'rate_bps' => 'integer',
        'amount_kobo' => 'integer',
    ];

    /**
     * The rate is the operator's business. It is hidden so a partner-facing
     * response cannot carry it: a partner who saw it could read their own
     * negotiated rate.
     */
    protected $hidden = ['rate_bps'];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function bill()
    {
        return $this->belongsTo(TermBill::class, 'term_bill_id');
    }
}
