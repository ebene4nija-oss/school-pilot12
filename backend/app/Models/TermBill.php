<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One school's SchoolPilot bill for one term. Platform-level: read and
 * written only from the operator console, so it carries no tenant scope.
 */
class TermBill extends Model
{
    protected $fillable = [
        'school_id',
        'term_id',
        'plan',
        'active_students',
        'rate_kobo',
        'boarding_students',
        'boarding_rate_kobo',
        'amount_kobo',
        'status',
        'issued_by',
        'paid_at',
        'note',
    ];

    protected $casts = [
        'active_students' => 'integer',
        'rate_kobo' => 'integer',
        'boarding_students' => 'integer',
        'boarding_rate_kobo' => 'integer',
        'amount_kobo' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }
}
