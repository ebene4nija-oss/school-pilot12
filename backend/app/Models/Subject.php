<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id', 'name', 'code', 'category', 'department',
        'description', 'credit_units', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Teachers assigned to teach this subject.
     */
    public function teachers()
    {
        return $this->belongsToMany(User::class, 'teacher_subjects', 'subject_id', 'teacher_id')
                    ->withPivot('class_id', 'is_primary')
                    ->withTimestamps();
    }

    /**
     * Classes where this subject is offered.
     */
    public function classes()
    {
        return $this->belongsToMany(\App\Models\SchoolClass::class, 'class_subjects', 'subject_id', 'class_id')
                    ->withPivot('is_compulsory', 'max_students')
                    ->withTimestamps();
    }

    /**
     * Students enrolled in this subject.
     */
    public function students()
    {
        return $this->belongsToMany(Student::class, 'student_subjects', 'subject_id', 'student_id')
                    ->withPivot('term_id', 'enrollment_type', 'status', 'enrolled_at')
                    ->withTimestamps();
    }
}
