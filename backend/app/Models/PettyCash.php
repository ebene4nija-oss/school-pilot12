<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PettyCash extends Model
{
    use BelongsToTenant;

    protected $table = 'petty_cashes';

    protected $fillable = [
        'school_id',
        'custodian_id',
        'type',
        'amount',
        'purpose',
        'entry_date',
    ];

    protected $casts = [
        'entry_date' => 'date',
    ];

    public function custodian()
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }
}
