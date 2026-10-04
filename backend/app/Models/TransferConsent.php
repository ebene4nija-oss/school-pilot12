<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A guardian's decision for one category of a transfer. The transfer itself
 * and each sensitive category are consented to separately; nothing sensitive
 * travels by default.
 */
class TransferConsent extends Model
{
    use BelongsToTenant;

    public const CATEGORIES = [
        'transfer' => 'The transfer and its academic records',
        'health' => 'Health notes',
        'special_needs' => 'Special educational needs',
        'discipline' => 'Discipline records',
    ];

    protected $fillable = ['school_id', 'transfer_id', 'category', 'given', 'given_by', 'ip_address', 'decided_at'];

    protected $casts = ['given' => 'boolean', 'decided_at' => 'datetime'];
}
