<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HomeworkSubmission extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'homework_submissions';

    protected $fillable = [
        'school_id',
        'homework_id',
        'student_id',
        'body',
        'attachment_path',
        'submitted_at',
        'is_late',
        'marks_awarded',
        'marks_available',
        'teacher_feedback',
        'graded_by',
        'graded_at',
        'status',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'is_late' => 'boolean',
        'marks_awarded' => 'decimal:2',
        'marks_available' => 'decimal:2',
    ];

    public function homework()
    {
        return $this->belongsTo(Homework::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function grader()
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    public function isGraded(): bool
    {
        return $this->status === 'graded' && $this->graded_at !== null;
    }
}
