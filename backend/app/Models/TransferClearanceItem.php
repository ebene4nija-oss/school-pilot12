<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferClearanceItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'transfer_id', 'item', 'status', 'note', 'override_reason', 'updated_by'];
}
