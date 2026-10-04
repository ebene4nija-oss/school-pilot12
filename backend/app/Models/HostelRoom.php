<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelRoom extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_rooms';

    protected $fillable = [
        'school_id',
        'hostel_id',
        'block',
        'floor',
        'name',
        'room_type',
        'capacity',
        'status',
        'facilities',
        'restrictions',
        'last_inspected_at',
    ];

    protected $casts = [
        'facilities' => 'array',
        'last_inspected_at' => 'datetime',
    ];

    public function beds()
    {
        return $this->hasMany(HostelBed::class, 'room_id');
    }

    public function hostel()
    {
        return $this->belongsTo(Hostel::class);
    }
}
