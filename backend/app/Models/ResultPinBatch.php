<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One wholesale purchase of PIN inventory by a school from SchoolPilot.
 *
 * A batch is the audit trail for money that changed hands at the platform
 * level. The PINs it minted hang off it, so "how many of the 500 they bought
 * in March are still unsold" is answerable without guesswork.
 */
class ResultPinBatch extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'result_pin_batches';

    protected $fillable = [
        'school_id',
        'reference',
        'quantity',
        'unit_price',
        'total_amount',
        'source',
        'gateway',
        'status',
        'granted_by',
        'notes',
        'paid_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function pins()
    {
        return $this->hasMany(ResultPin::class, 'batch_id');
    }

    public function grantedBy()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
