<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelAssetIssue extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_asset_issues';

    protected $fillable = [
        'school_id',
        'asset_id',
        'student_id',
        'quantity',
        'issued_at',
        'returned_at',
        'condition_out',
        'condition_in',
        'status',
        'issued_by',
        'received_by',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'returned_at' => 'datetime',
    ];
}
