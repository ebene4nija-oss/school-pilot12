<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Two schools' records of one child, linked only through an approved transfer
 * and a person's confirmation. Never created from a name match alone. Not
 * tenant-scoped: it spans two schools, and is read only by transfer code.
 */
class StudentIdentityLink extends Model
{
    protected $fillable = ['from_student_id', 'to_student_id', 'transfer_id', 'matched_on', 'confirmed_by'];

    protected $casts = ['matched_on' => 'array'];
}
