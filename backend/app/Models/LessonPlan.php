<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A lesson plan, linkable to materials, a virtual class and homework. */
class LessonPlan extends Model
{
    use BelongsToTenant;

    protected $table = 'lesson_plans';

    protected $fillable = [
        'school_id', 'teacher_id', 'class_id', 'subject_id', 'session_id', 'term_id', 'week', 'topic', 'subtopic',
        'objectives', 'resources', 'activities', 'assessment', 'outcomes', 'duration_minutes', 'status',
        'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function materials()
    {
        return $this->belongsToMany(LearningMaterial::class, 'lesson_plan_materials', 'lesson_plan_id', 'material_id');
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
