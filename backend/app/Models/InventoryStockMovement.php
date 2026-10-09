<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One change to an item's stock: received, issued, adjusted or returned. */
class InventoryStockMovement extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_stock_movements';

    protected $fillable = ['school_id', 'item_id', 'change', 'reason', 'note', 'issue_id', 'balance_after', 'created_by'];

    protected $casts = ['change' => 'integer', 'balance_after' => 'integer'];
}
