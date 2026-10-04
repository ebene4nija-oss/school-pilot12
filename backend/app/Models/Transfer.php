<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One school's side of a transfer. An outgoing and an incoming transfer for
 * the same child share a reference; each school sees and changes only its own.
 */
class Transfer extends Model
{
    use BelongsToTenant;

    public const STATUSES = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'awaiting_consent' => 'Awaiting consent',
        'clearance_pending' => 'Clearance pending',
        'pending_approval' => 'Pending approval',
        'approved' => 'Approved',
        'awaiting_acceptance' => 'Awaiting acceptance',
        'records_requested' => 'Records requested',
        'records_shared' => 'Records shared',
        'under_verification' => 'Under verification',
        'additional_info_required' => 'Additional information required',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    public const OPEN = ['draft', 'submitted', 'awaiting_consent', 'clearance_pending', 'pending_approval', 'approved',
        'awaiting_acceptance', 'records_requested', 'records_shared', 'under_verification', 'additional_info_required', 'accepted'];

    protected $fillable = [
        'school_id', 'reference', 'direction', 'status', 'student_id', 'enrollment_id', 'reason',
        'requested_transfer_date', 'other_school_name', 'other_school_id', 'linked_transfer_id',
        'requested_by_type', 'requested_by', 'partial_term', 'applicant', 'package_options',
        'current_step', 'steps', 'transfer_code_hash', 'transfer_code_expires_at',
        'placement_class_id', 'new_student_id', 'completed_at',
    ];

    protected $casts = [
        'applicant' => 'array',
        'package_options' => 'array',
        'steps' => 'array',
        'partial_term' => 'boolean',
        'requested_transfer_date' => 'date',
        'transfer_code_expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'current_step' => 'integer',
    ];

    protected $hidden = ['transfer_code_hash'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function events()
    {
        return $this->hasMany(TransferEvent::class)->orderBy('id');
    }

    public function consents()
    {
        return $this->hasMany(TransferConsent::class);
    }

    public function clearanceItems()
    {
        return $this->hasMany(TransferClearanceItem::class);
    }

    public function packages()
    {
        return $this->hasMany(TransferPackage::class)->orderByDesc('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
