<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An operator's note on a school.
 *
 * Deliberately not `BelongsToTenant`: nothing school-side reads this table, and
 * it is reached only through the super-admin routes. Scoping it to a tenant
 * would suggest a school could see it, which it must not.
 */
class SchoolSupportNote extends Model
{
    protected $fillable = [
        'school_id',
        'user_id',
        'body',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
