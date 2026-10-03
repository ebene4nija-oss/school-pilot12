<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One child's rating on one developmental indicator for one term. */
class DevelopmentalRating extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'term_id', 'student_id', 'indicator_id', 'rating', 'observation', 'recorded_by'];
}
