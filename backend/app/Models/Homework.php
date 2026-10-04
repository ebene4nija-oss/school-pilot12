<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Homework extends Model
{
    use BelongsToTenant;

    protected $table = 'homework';

    protected $fillable = [
        'school_id',
        'class_id',
        'subject_id',
        'teacher_id',
        'title',
        'description',
        'due_date',
        'arm_id',
        'lesson_plan_id',
        'max_score',
        'submission_type',
        'allow_late',
        'allow_parent_assist',
        'status',
        'published_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'allow_late' => 'boolean',
        'allow_parent_assist' => 'boolean',
        'published_at' => 'datetime',
    ];

    /** Specific students; empty means everyone in the class (or arm). */
    public function targetStudentIds(): array
    {
        return \Illuminate\Support\Facades\DB::table('homework_targets')->where('homework_id', $this->id)->pluck('student_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Whether this homework was set for this student. */
    public function isFor(Student $student): bool
    {
        if ((int) $this->class_id !== (int) $student->class_id) {
            return false;
        }
        if ($this->arm_id && (int) $this->arm_id !== (int) $student->arm_id) {
            return false;
        }
        $targets = $this->targetStudentIds();

        return $targets === [] || in_array((int) $student->id, $targets, true);
    }

    /** Narrow a homework query to what one student was set (published only). */
    public function scopeSetFor($query, Student $student)
    {
        return $query->where('class_id', $student->class_id)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'published'))
            ->where(fn ($q) => $q->whereNull('arm_id')->orWhere('arm_id', $student->arm_id))
            ->where(fn ($q) => $q->whereNotExists(fn ($s) => $s->from('homework_targets')->whereColumn('homework_targets.homework_id', 'homework.id'))
                ->orWhereExists(fn ($s) => $s->from('homework_targets')->whereColumn('homework_targets.homework_id', 'homework.id')->where('homework_targets.student_id', $student->id)));
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
}
