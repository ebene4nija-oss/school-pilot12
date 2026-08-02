<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'vendor_id',
        'category',
        'title',
        'amount',
        'expense_date',
        'payment_method',
        'status',
        'notes',
    ];

    protected $casts = [
        'expense_date' => 'date',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}
