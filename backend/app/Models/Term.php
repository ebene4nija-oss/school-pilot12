<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Term extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'session_id', 'name', 'start_date', 'end_date', 'is_current'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    public function session()
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }
}
