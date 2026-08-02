<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Term extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'session_id', 'name', 'start_date', 'end_date', 'is_current'];
}
