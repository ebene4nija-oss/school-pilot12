<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdmissionAssessment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'application_id', 'kind', 'scheduled_at', 'location', 'cbt_exam_id', 'score', 'max_score', 'notes', 'recorded_by'];

    protected $casts = ['scheduled_at' => 'datetime'];
}
