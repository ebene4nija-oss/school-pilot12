<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A user's named set of student-list filters and columns. */
class StudentSavedView extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'user_id', 'name', 'filters', 'columns'];

    protected $casts = ['filters' => 'array', 'columns' => 'array'];
}
