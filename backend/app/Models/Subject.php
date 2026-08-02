<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'name', 'code', 'category'];
}
