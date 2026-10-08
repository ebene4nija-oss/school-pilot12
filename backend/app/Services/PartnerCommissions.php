<?php

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\School;
use App\Models\TermBill;

/**
 * Turns paid term bills into partner commission.
 *
 * A bill earns a commission only when it is marked paid. Waived and reopened
 * bills earn nothing, trial schools earn nothing (they have no paid bill), and
 * a school that stops paying stops earning because no new bill is paid. The
 * commission is on the full bill, boarders included.
 */
class PartnerCommissions
{
    /**
     * Call after a bill's status changes. Accrues on paid; voids an unsettled
     * commission when the bill leaves paid. Returns the commission, if any.
     */
    public function sync(TermBill $bill): ?PartnerCommission
    {
        return $bill->status === 'paid' ? $this->accrue($bill) : $this->void($bill);
    }

    private function accrue(TermBill $bill): ?PartnerCommission
    {
        $partnerId = School::query()->whereKey($bill->school_id)->value('partner_id');
        $existing = PartnerCommission::where('term_bill_id', $bill->id)->first();

        // Already counted (or already settled): marking paid twice changes nothing.
        if ($existing && $existing->status !== 'void') {
            return $existing;
        }

        $partner = $partnerId ? Partner::find($partnerId) : null;

        // A partner who is not active earns on nothing new.
        if (! $partner || $partner->status !== 'active') {
            return null;
        }

        $rate = $partner->rateBps();
        $values = [
            'partner_id' => $partner->id,
            'school_id' => $bill->school_id,
            'rate_bps' => $rate,
            'amount_kobo' => (int) round($bill->amount_kobo * $rate / 10000),
            'status' => 'accrued',
            'payout_id' => null,
        ];

        return $existing
            ? tap($existing)->update($values)
            : PartnerCommission::create($values + ['term_bill_id' => $bill->id]);
    }

    /** A commission already paid out is left alone; only an accrued one is voided. */
    private function void(TermBill $bill): ?PartnerCommission
    {
        $commission = PartnerCommission::where('term_bill_id', $bill->id)->where('status', 'accrued')->first();
        $commission?->update(['status' => 'void']);

        return $commission;
    }
}
