<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolCalendarEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'title',
        'description',
        'category',
        'start_date',
        'end_date',
        'is_public',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_public' => 'boolean',
    ];
}
