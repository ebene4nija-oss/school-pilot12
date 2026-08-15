<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SchoolPilot's negotiated wholesale rate with one school.
 *
 * Overrides the published rate card in `ResultPinPriceTier` for this school
 * only. Platform-level commercial terms, so deliberately NOT tenant-scoped and
 * never writable from the school side — see the migration for why.
 *
 * Resolution lives in `App\Services\ResultPinPricing`, not here, because a
 * price is the answer to "this school, this quantity" and needs both tables.
 */
class ResultPinSchoolRate extends Model
{
    use HasFactory;

    protected $table = 'result_pin_school_rates';

    protected $fillable = [
        'school_id',
        'unit_price',
        'is_active',
        'notes',
        'set_by',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'unit_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /**
     * The live negotiated rate for a school, or null if it buys at card price.
     *
     * Null school_id — a SchoolPilot operator, who belongs to no school — has no
     * negotiated rate by definition, and must not match somebody else's.
     */
    public static function activeFor(?int $schoolId): ?self
    {
        if ($schoolId === null) {
            return null;
        }

        return static::where('school_id', $schoolId)
            ->where('is_active', true)
            ->first();
    }
}
