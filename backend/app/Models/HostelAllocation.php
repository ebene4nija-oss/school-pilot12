<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelAllocation extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_allocations';

    protected $fillable = [
        'school_id',
        'enrollment_id',
        'student_id',
        'bed_id',
        'starts_on',
        'ends_on',
        'status',
        'reason',
        'override_reason',
        'allocated_by',
        'ended_by',
        'end_reason',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function bed()
    {
        return $this->belongsTo(HostelBed::class, 'bed_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
