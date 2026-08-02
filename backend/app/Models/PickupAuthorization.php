<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PickupAuthorization extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'student_id',
        'parent_id',
        'authorized_person_name',
        'authorized_person_phone',
        'relationship',
        'photo_path',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }
}
