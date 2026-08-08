<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'leave_requests';

    protected $fillable = [
        'school_id',
        'staff_id',
        'leave_type',
        'start_date',
        'end_date',
        'days_requested',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'decided_at' => 'datetime',
        'days_requested' => 'integer',
    ];

    public const TYPES = ['annual', 'sick', 'maternity', 'compassionate', 'study'];

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
