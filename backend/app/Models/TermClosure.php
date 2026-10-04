<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A class's term confirmed closed, after which its results are locked snapshots. */
class TermClosure extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'term_id', 'class_id', 'closed_by', 'students', 'from_approved_broadsheet'];

    protected $casts = ['from_approved_broadsheet' => 'boolean'];
}
