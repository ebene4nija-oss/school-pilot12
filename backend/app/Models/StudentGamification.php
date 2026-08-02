<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentGamification extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'school_id',
        'student_id',
        'points',
        'current_streak',
        'last_activity_date',
        'badges',
    ];

    protected $casts = [
        'badges' => 'array',
        'last_activity_date' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
