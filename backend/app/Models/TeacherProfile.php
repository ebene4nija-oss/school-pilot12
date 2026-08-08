<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id', 'user_id', 'employee_id', 'qualification', 'specialization',
        'years_of_experience', 'certifications', 'subjects_taught',
        'performance_rating', 'date_joined', 'contract_type',
    ];

    protected $casts = [
        'certifications' => 'array',
        'subjects_taught' => 'array',
        'date_joined' => 'date',
        'performance_rating' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
