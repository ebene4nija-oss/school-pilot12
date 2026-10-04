<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelBed extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_beds';

    protected $fillable = [
        'school_id',
        'room_id',
        'label',
        'status',
        'status_note',
        'status_changed_at',
    ];

    protected $casts = [
        'status_changed_at' => 'datetime',
    ];

    public function room()
    {
        return $this->belongsTo(HostelRoom::class, 'room_id');
    }
}
