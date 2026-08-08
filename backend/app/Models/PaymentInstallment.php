<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One agreed part-payment of an invoice.
 *
 * The table has existed since the Phase 1 migration with no code behind it.
 * Installment plans matter here specifically: Nigerian private-school fees are
 * commonly paid in two or three tranches across a term, and a system that only
 * understands "paid" or "unpaid" pushes bursars back into a paper ledger.
 */
class PaymentInstallment extends Model
{
    protected $table = 'payment_installments';

    protected $fillable = [
        'invoice_id',
        'amount',
        'due_date',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'paid'
            && $this->due_date !== null
            && $this->due_date->endOfDay()->isPast();
    }
}
