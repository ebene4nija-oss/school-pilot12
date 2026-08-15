<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolClass extends Model
{
    use BelongsToTenant;

    protected $table = 'classes';

    protected $fillable = ['school_id', 'name', 'level_category', 'order_index', 'is_exit_class'];

    protected $casts = ['is_exit_class' => 'boolean'];

    public function arms()
    {
        return $this->hasMany(Arm::class, 'class_id');
    }
}
