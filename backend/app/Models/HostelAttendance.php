<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelAttendance extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_attendance';

    protected $fillable = [
        'school_id',
        'rollcall_id',
        'student_id',
        'status',
        'note',
        'marked_by',
        'correction_reason',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
