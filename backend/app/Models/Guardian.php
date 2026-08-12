<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Guardian extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'user_id',
        'occupation',
        'relationship',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * `is_primary` is carried explicitly because Eloquent selects only the two
     * foreign keys off a pivot otherwise — without it `$student->pivot->is_primary`
     * is null on every row, and `/parent/children` would report every guardian
     * as secondary. The column decides who the school calls first.
     */
    public function students()
    {
        return $this->belongsToMany(Student::class, 'student_guardian')
            ->withPivot('is_primary');
    }
}
