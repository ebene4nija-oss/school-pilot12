<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One operator's time-limited access to one school, with the reason given.
 *
 * Not `BelongsToTenant`: it is read in the middleware that establishes the
 * tenant, before there is one, and by the operator console across schools.
 */
class PlatformSchoolAccess extends Model
{
    protected $table = 'platform_school_access';

    protected $fillable = [
        'user_id',
        'school_id',
        'reason',
        'expires_at',
        'closed_at',
        'ip_address',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /** Not closed and not yet expired. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('closed_at')->where('expires_at', '>', now());
    }
}
