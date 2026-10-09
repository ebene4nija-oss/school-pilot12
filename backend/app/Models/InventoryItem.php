<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Something the school sells or hands to students. Field meanings are in the inventory migration. */
class InventoryItem extends Model
{
    use BelongsToTenant;

    public const CATEGORIES = ['uniform', 'book', 'consumable', 'other'];

    protected $table = 'inventory_items';

    protected $fillable = ['school_id', 'name', 'category', 'variant', 'unit_price', 'charge_to_fees', 'quantity_on_hand', 'reorder_level', 'is_active'];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'charge_to_fees' => 'boolean',
        'quantity_on_hand' => 'integer',
        'reorder_level' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['label', 'low_stock'];

    public function getLabelAttribute(): string
    {
        return $this->variant ? "{$this->name} ({$this->variant})" : $this->name;
    }

    public function getLowStockAttribute(): bool
    {
        return $this->is_active && $this->quantity_on_hand <= $this->reorder_level;
    }
}
