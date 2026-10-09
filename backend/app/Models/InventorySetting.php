<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Whether the school uses the store. Off until the school opts in. */
class InventorySetting extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_settings';

    protected $fillable = ['school_id', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self(['school_id' => $schoolId, 'enabled' => false]);
    }

    public static function enabledFor(int $schoolId): bool
    {
        return (bool) static::for($schoolId)->enabled;
    }
}
