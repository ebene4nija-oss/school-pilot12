<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BehaviorReport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'student_id',
        'recorded_by',
        'type',
        'title',
        'description',
        'incident_date',
    ];

    protected $casts = [
        'incident_date' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
