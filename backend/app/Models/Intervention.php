<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A plan to support a student, with a measurable target and reviews. */
class Intervention extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUSES = ['planned', 'ongoing', 'completed', 'cancelled'];

    protected $fillable = [
        'school_id', 'student_id', 'subject_id', 'topic', 'issue', 'baseline', 'target', 'action',
        'responsible_teacher_id', 'start_date', 'review_date', 'status', 'outcome', 'plain_summary',
        'created_by', 'assigned_by',
    ];

    protected $casts = ['start_date' => 'date', 'review_date' => 'date'];

    public function reviews()
    {
        return $this->hasMany(InterventionReview::class)->orderBy('id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'responsible_teacher_id');
    }
}
