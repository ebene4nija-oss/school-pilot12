<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassTeacherAssignment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'session_id',
        'class_id',
        'arm_id',
        'form_teacher_id',
        'assistant_teacher_id',
        'assigned_by',
    ];

    public function session()
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function arm()
    {
        return $this->belongsTo(Arm::class, 'arm_id');
    }

    public function formTeacher()
    {
        return $this->belongsTo(User::class, 'form_teacher_id');
    }

    public function assistantTeacher()
    {
        return $this->belongsTo(User::class, 'assistant_teacher_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * The form teacher of a given placement in a given session, if any.
     *
     * Takes an explicit school and drops the global scope, because the first
     * caller is report-card rendering, which runs on a queue with no tenant
     * context established. `whereNull` rather than `where(..., null)` on the
     * arm: an unstreamed class stores NULL, and `arm_id = NULL` is never true
     * in SQL.
     */
    public static function formTeacherFor(int $schoolId, int $sessionId, int $classId, ?int $armId): ?User
    {
        $assignment = static::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('session_id', $sessionId)
            ->where('class_id', $classId)
            ->when($armId === null,
                fn ($q) => $q->whereNull('arm_id'),
                fn ($q) => $q->where('arm_id', $armId),
            )
            ->with('formTeacher:id,name')
            ->first();

        return $assignment?->formTeacher;
    }
}
