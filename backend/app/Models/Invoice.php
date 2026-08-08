<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'term_id', 'student_id', 'invoice_number', 'total_amount', 'amount_paid', 'status', 'due_date'];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /** What is still owed on this invoice, in naira. */
    public function balance(): float
    {
        return round((float) $this->total_amount - (float) $this->amount_paid, 2);
    }

    public function isSettled(): bool
    {
        return $this->balance() <= 0;
    }
}
