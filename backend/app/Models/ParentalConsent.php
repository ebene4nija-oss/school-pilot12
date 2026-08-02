<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentalConsent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'guardian_id',
        'student_id',
        'consent_given',
        'ai_cross_border_consent_given',
        'ip_address',
        'consented_at',
        'withdrawn_at',
        'notes',
    ];

    protected $casts = [
        'consent_given' => 'boolean',
        'ai_cross_border_consent_given' => 'boolean',
        'consented_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
