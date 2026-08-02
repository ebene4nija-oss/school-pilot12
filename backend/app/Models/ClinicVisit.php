<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicVisit extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'student_id',
        'symptoms',
        'treatment',
        'attending_nurse',
        'visited_at',
    ];

    /**
     * NDPA Compliance: Encrypt sensitive health data at rest
     */
    protected $casts = [
        'symptoms' => 'encrypted',
        'treatment' => 'encrypted',
        'visited_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
