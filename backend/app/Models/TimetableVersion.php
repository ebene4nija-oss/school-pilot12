<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimetableVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'name',
        'status',
        'academic_session_id',
        'term_id',
        'published_at',
    ];

    public function entries()
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
