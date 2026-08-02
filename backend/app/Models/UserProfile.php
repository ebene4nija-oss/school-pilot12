<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'school_id',
        'user_id',
        'role',
        'phone',
        'address',
        'avatar_url',
        'two_factor_enabled',
        'two_factor_secret',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
