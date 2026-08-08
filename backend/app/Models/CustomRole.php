<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomRole extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id', 'name', 'slug', 'description', 'permissions', 'is_system_default',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system_default' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles', 'custom_role_id', 'user_id')
                    ->withPivot('assigned_by', 'assigned_at')
                    ->withTimestamps();
    }

    public function hasPermission(string $permission): bool
    {
        return is_array($this->permissions) && in_array($permission, $this->permissions);
    }
}
