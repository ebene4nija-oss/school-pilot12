<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterventionReview extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'intervention_id', 'progress', 'result', 'next_review_date', 'reviewed_by'];

    protected $casts = ['next_review_date' => 'date'];
}
