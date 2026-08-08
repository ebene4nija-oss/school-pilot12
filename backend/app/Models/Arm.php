<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Arm extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'class_id', 'name'];

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
