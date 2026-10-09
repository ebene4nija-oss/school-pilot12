<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One item on an issue. Reached through its issue, which carries the school. */
class InventoryIssueLine extends Model
{
    protected $table = 'inventory_issue_lines';

    protected $fillable = ['issue_id', 'item_id', 'item_name', 'variant', 'quantity', 'unit_price', 'charged', 'status'];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'charged' => 'boolean',
    ];
}
