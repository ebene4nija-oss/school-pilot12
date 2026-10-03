<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScoreEntry extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'school_id',
        'term_id',
        'student_id',
        'subject_id',
        'first_ca',
        'second_ca',
        'exam',
        'total_score',
        'grade',
        'position_in_class',
        'teacher_comment',
        'ai_comment_status',
        'ai_comment_review',
        'ai_comment_generated_at',
        'ai_comment_reviewed_at',
        'component_scores',
        'result_state',
    ];

    /** A mark per CA-scheme part, keyed by part: `{ca1: 8, ca2: 9, exam: 55}`. */
    protected $casts = [
        'component_scores' => 'array',
    ];
    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }
}
