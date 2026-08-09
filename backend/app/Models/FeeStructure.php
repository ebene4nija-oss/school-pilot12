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

    /** Null means the fee applies school-wide rather than to one class. */
    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Whether this fee falls on a student sitting in `$classId`.
     *
     * School-wide fees (null `class_id`) catch everyone, including students not
     * yet assigned to a class.
     */
    public function appliesToClass(?int $classId): bool
    {
        return $this->class_id === null || (int) $this->class_id === $classId;
    }
}
