<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ID card policy for one school (TASK 18). Field meanings are in the lifecycle migration. */
class IdCardSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'id_card_settings';

    protected $fillable = [
        'school_id', 'enabled_types', 'valid_only_when_issued', 'staff_validity_months', 'expiry_warning_days',
        'digital_cards_enabled', 'parents_see_digital_card', 'log_verifications',
    ];

    protected $casts = [
        'enabled_types' => 'array',
        'valid_only_when_issued' => 'boolean',
        'staff_validity_months' => 'integer',
        'expiry_warning_days' => 'integer',
        'digital_cards_enabled' => 'boolean',
        'parents_see_digital_card' => 'boolean',
        'log_verifications' => 'boolean',
    ];

    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self(['school_id' => $schoolId, 'enabled_types' => ['student', 'staff'], 'valid_only_when_issued' => false, 'staff_validity_months' => null, 'expiry_warning_days' => 30, 'digital_cards_enabled' => false, 'parents_see_digital_card' => false, 'log_verifications' => true]);
    }
}
