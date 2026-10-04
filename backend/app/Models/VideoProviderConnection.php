<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A school's own Zoom or Google Workspace API access, used to read who was
 * in a virtual class. Credentials are encrypted at rest and never returned
 * by the API.
 */
class VideoProviderConnection extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'provider', 'credentials', 'status', 'last_error', 'last_synced_at', 'connected_by'];

    protected $hidden = ['credentials'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'last_synced_at' => 'datetime',
    ];
}
