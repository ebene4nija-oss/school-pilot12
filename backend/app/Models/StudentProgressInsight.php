<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A statement the rule engine made about a student, stored with the rule and
 * the inputs that produced it, so it can always be traced to the scores.
 */
class StudentProgressInsight extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'student_id', 'term_id', 'rule', 'category', 'severity', 'text', 'inputs', 'evidence', 'generated_at'];

    protected $casts = ['inputs' => 'array', 'evidence' => 'array', 'generated_at' => 'datetime'];
}
