<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One pupil's ratings and comments for one term. See the migration. */
class StudentTermReport extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'student_id', 'term_id', 'ratings', 'form_teacher_comment', 'principal_comment', 'updated_by'];

    protected $casts = ['ratings' => 'array'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
