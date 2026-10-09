<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The school's bus fee (one flat amount per rider per term) and reminder window. */
class TransportSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'transport_settings';

    protected $fillable = ['school_id', 'termly_fee', 'renewal_warning_days'];

    protected $casts = ['termly_fee' => 'decimal:2', 'renewal_warning_days' => 'integer'];

    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self(['school_id' => $schoolId, 'termly_fee' => 0, 'renewal_warning_days' => 30]);
    }
}
