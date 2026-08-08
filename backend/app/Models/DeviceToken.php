<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    use BelongsToTenant;

    protected $table = 'device_tokens';

    protected $fillable = [
        'school_id',
        'user_id',
        'token',
        'platform',
        'device_name',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    protected $hidden = [
        // The raw token is a send credential for that device. It never needs
        // to travel back to a client.
        'token',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
