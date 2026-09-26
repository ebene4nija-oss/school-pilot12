<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamTimetable extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'term_id', 'name', 'status', 'published_at'];

    protected $casts = ['published_at' => 'datetime'];

    public function sittings()
    {
        return $this->hasMany(ExamSitting::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }
}
