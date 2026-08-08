<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentClassHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'student_class_history';

    protected $fillable = [
        'school_id',
        'student_id',
        'from_class_id',
        'from_arm_id',
        'to_class_id',
        'to_arm_id',
        'session_id',
        'action',
        'remarks',
        'performed_by',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function fromClass()
    {
        return $this->belongsTo(SchoolClass::class, 'from_class_id');
    }

    public function toClass()
    {
        return $this->belongsTo(SchoolClass::class, 'to_class_id');
    }

    public function fromArm()
    {
        return $this->belongsTo(Arm::class, 'from_arm_id');
    }

    public function toArm()
    {
        return $this->belongsTo(Arm::class, 'to_arm_id');
    }

    public function session()
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
