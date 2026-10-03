<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A nursery developmental area a school rates, e.g. "Social interaction". */
class DevelopmentalIndicator extends Model
{
    use BelongsToTenant;

    public const RATINGS = [
        'exceeding' => 'Exceeding expectations',
        'meeting' => 'Meeting expectations',
        'developing' => 'Developing',
        'needs_support' => 'Needs support',
    ];

    protected $fillable = ['school_id', 'name', 'order_index', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
