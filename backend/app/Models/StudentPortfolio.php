<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentPortfolio extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id', 'student_id', 'achievement_type', 'title',
        'description', 'date_achieved', 'evidence_url', 'verified_by', 'verified_at',
    ];

    protected $casts = [
        'date_achieved' => 'date',
        'verified_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
