<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'school_id',
        'user_id',
        'class_id',
        'arm_id',
        'admission_number',
        'date_of_birth',
        'gender',
        'state_of_origin',
        'lga',
        'religion',
        'blood_group',
        'allergies',
        'medical_notes',
        'previous_school',
        'status',
    ];

    /**
     * NDPA Compliance: Application-level encryption for sensitive personal/health data
     */
    protected $casts = [
        'blood_group' => 'encrypted',
        'allergies' => 'encrypted:array',
        'medical_notes' => 'encrypted',
        'date_of_birth' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
