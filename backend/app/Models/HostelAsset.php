<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelAsset extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_assets';

    protected $fillable = [
        'school_id',
        'hostel_id',
        'room_id',
        'name',
        'code',
        'quantity',
        'condition',
        'issuable',
    ];

    protected $casts = [
        'issuable' => 'boolean',
    ];
}
