<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A guardian's online purchase of one result-checker PIN, from the school.
 *
 * Sits pending until the gateway webhook confirms. The PIN is only allocated
 * and bound at that point — an abandoned checkout must not consume a school's
 * stock, and a client-side "payment complete" callback is not evidence.
 */
class ResultPinSale extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'result_pin_sales';

    protected $fillable = [
        'school_id',
        'student_id',
        'term_id',
        'purchased_by',
        'reference',
        'amount',
        'gateway',
        'status',
        'result_pin_id',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function purchaser()
    {
        return $this->belongsTo(User::class, 'purchased_by');
    }

    public function pin()
    {
        return $this->belongsTo(ResultPin::class, 'result_pin_id');
    }
}
