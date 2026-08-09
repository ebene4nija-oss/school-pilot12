<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One billed line on an invoice.
 *
 * `title` and `amount` are copied from the fee structure rather than read
 * through the relation: repricing tuition next term must not silently rewrite
 * what a family was billed — and paid — this term.
 */
class InvoiceItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'invoice_id', 'fee_structure_id', 'title', 'amount'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function feeStructure()
    {
        return $this->belongsTo(FeeStructure::class);
    }
}
