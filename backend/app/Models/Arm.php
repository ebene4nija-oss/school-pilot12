<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Arm extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'class_id', 'name', 'stream', 'is_merit'];

    /** `is_merit` marks the school's "best students" arm; staff-only. */
    protected $casts = ['is_merit' => 'boolean'];

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
