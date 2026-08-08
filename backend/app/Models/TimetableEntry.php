<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimetableEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'timetable_version_id',
        'school_class_id',
        'subject_id',
        'subject_name',
        'teacher_id',
        'day_of_week',
        'slot_name',
        'start_time',
        'end_time',
        'is_break',
    ];

    public function version()
    {
        return $this->belongsTo(TimetableVersion::class, 'timetable_version_id');
    }
}
