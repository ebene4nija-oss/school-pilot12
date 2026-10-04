<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdmissionDocument extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'application_id', 'type', 'status', 'note', 'updated_by'];
}
