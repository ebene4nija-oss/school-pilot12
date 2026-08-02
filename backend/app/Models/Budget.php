<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'term_id',
        'category',
        'allocated_amount',
        'fiscal_year',
    ];

    public function term()
    {
        return $this->belongsTo(Term::class);
    }
}
