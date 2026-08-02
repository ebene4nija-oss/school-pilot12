<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'school_id',
        'term_id',
        'student_id',
        'staff_id',
        'date',
        'status',
        'latitude',
        'longitude',
    ];

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
