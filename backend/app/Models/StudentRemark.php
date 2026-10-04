<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A teacher's remark on a student's progress, with who may read it. */
class StudentRemark extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const CATEGORIES = [
        'academic' => 'Academic performance',
        'subject_progress' => 'Subject progress',
        'difficulty' => 'Learning difficulty observed',
        'participation' => 'Participation',
        'homework' => 'Homework completion',
        'improvement' => 'Improvement over time',
        'support' => 'Recommended support',
    ];

    protected $fillable = ['school_id', 'student_id', 'subject_id', 'term_id', 'teacher_id', 'category', 'body', 'visibility'];

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
