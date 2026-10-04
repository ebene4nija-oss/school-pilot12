<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelCharge extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_charges';

    protected $fillable = [
        'school_id',
        'student_id',
        'term_id',
        'charge_type',
        'title',
        'amount',
        'dedupe_key',
        'status',
        'invoice_id',
        'invoice_item_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];
}
