<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One platform-wide setting. See App\Support\PlatformSettings for which keys
 * exist and how they reach the services that use them.
 *
 * Not tenant-scoped: these belong to the platform, not to any school.
 */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected $casts = [
        // Ciphertext at rest, secrets and plain values alike — see the migration.
        'value' => 'encrypted',
    ];

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
