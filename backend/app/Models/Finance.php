<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeStructure extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'term_id', 'class_id', 'title', 'amount', 'is_mandatory'];
}

class Invoice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'term_id', 'student_id', 'invoice_number', 'total_amount', 'amount_paid', 'status', 'due_date'];
}

class Payment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'invoice_id', 'reference', 'amount', 'gateway', 'status', 'paid_at'];
}
