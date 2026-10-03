<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A subject teacher's hand-in of one subject's marks for a broadsheet. */
class BroadsheetSubjectSubmission extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'broadsheet_id',
        'subject_id',
        'status',
        'returned_student_ids',
        'submitted_by',
        'submitted_at',
    ];

    protected $casts = [
        'returned_student_ids' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    /** Whether marks for this student may still be edited by their teacher. */
    public function allowsEditFor(int $studentId): bool
    {
        if ($this->status === 'draft') {
            return true;
        }

        if ($this->status !== 'returned') {
            return false;
        }

        $ids = $this->returned_student_ids;

        return $ids === null || in_array($studentId, array_map('intval', $ids), true);
    }
}
