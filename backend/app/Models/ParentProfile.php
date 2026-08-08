<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id', 'user_id', 'occupation', 'workplace',
        'relationship_to_student', 'alternate_phone', 'alternate_email',
        'custody_type', 'emergency_priority', 'preferred_contact_method',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
