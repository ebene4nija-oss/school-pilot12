<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A set of items handed out together, e.g. "JSS 1 new student pack". */
class InventoryPack extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_packs';

    protected $fillable = ['school_id', 'name', 'class_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(InventoryItem::class, 'inventory_pack_items', 'pack_id', 'item_id')->withPivot('quantity');
    }
}
