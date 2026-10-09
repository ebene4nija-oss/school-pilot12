<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One rider's bus fee for one term, on its way to the term invoice. */
class TransportCharge extends Model
{
    use BelongsToTenant;

    protected $table = 'transport_charges';

    protected $fillable = ['school_id', 'student_id', 'term_id', 'amount', 'status', 'invoice_id', 'invoice_item_id', 'created_by'];

    protected $casts = ['amount' => 'decimal:2'];
}
