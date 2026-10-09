<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An issued line on its way to the term invoice: pending, posted or void. */
class InventoryCharge extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_charges';

    protected $fillable = ['school_id', 'student_id', 'term_id', 'issue_line_id', 'title', 'amount', 'status', 'invoice_id', 'invoice_item_id'];

    protected $casts = ['amount' => 'decimal:2'];
}
