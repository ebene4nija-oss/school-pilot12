<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A student or parent reporting an error in the record, for staff to review. */
class RecordCorrectionRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'student_id', 'term_snapshot_id', 'raised_by', 'description', 'status', 'resolution', 'resolved_by', 'resolved_at'];

    protected $casts = ['resolved_at' => 'datetime'];
}
