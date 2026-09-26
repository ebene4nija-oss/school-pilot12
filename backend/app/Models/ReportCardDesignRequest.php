<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A school asking SchoolPilot to design its report card. See the migration.
 */
class ReportCardDesignRequest extends Model
{
    use BelongsToTenant;

    /** A school has at most one of these at a time. */
    public const OPEN = ['submitted', 'in_progress', 'template_ready', 'changes_requested'];

    protected $fillable = [
        'school_id', 'status', 'requirements', 'grading_scales', 'submitted_by', 'submitted_at',
        'handled_by', 'operator_notes', 'school_feedback', 'template_id', 'template_uploaded_at',
        'approved_at', 'files_purged_at',
    ];

    protected $casts = [
        'requirements' => 'array',
        'grading_scales' => 'array',
        'submitted_at' => 'datetime',
        'template_uploaded_at' => 'datetime',
        'approved_at' => 'datetime',
        'files_purged_at' => 'datetime',
    ];

    public function files()
    {
        return $this->hasMany(ReportCardDesignFile::class, 'request_id');
    }

    public function template()
    {
        return $this->belongsTo(ReportCardTemplate::class, 'template_id');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
