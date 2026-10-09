<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamSitting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id', 'exam_timetable_id', 'class_id', 'arm_id', 'subject_id',
        'paper', 'exam_date', 'start_time', 'end_time', 'venue', 'venue_mode', 'venue_id', 'invigilator_id',
    ];

    protected $casts = ['exam_date' => 'date:Y-m-d'];

    public function timetable()
    {
        return $this->belongsTo(ExamTimetable::class, 'exam_timetable_id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function arm()
    {
        return $this->belongsTo(Arm::class);
    }

    /** The hall from the school's venues, when one was picked. */
    public function hall()
    {
        return $this->belongsTo(Venue::class, 'venue_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function invigilator()
    {
        return $this->belongsTo(User::class, 'invigilator_id');
    }
}
