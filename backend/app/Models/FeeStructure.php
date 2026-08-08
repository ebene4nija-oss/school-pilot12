<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeStructure extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'term_id', 'class_id', 'title', 'amount', 'is_mandatory'];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_mandatory' => 'boolean',
    ];

    public function term()
    {
        return $this->belongsTo(Term::class);
    }
}
