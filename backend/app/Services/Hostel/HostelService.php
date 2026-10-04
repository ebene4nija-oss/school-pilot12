<?php

namespace App\Services\Hostel;

use App\Exceptions\DomainRefusal;
use App\Models\AuditLog;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelBed;
use App\Models\HostelCharge;
use App\Models\HostelEnrollment;
use App\Models\HostelFeeRule;
use App\Models\HostelLeaveRequest;
use App\Models\HostelMovement;
use App\Models\HostelRoom;
use App\Models\HostelSetting;
use App\Models\HostelStaffAssignment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\User;
use App\Services\Results\AuthorisationFailure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hostel rules (TASK 17 §26), in one place.
 *
 * Who may do what is decided by role and by hostel assignment: a warden's
 * authority is their hostel, not every hostel. Welfare and incident detail is
 * narrower still (§3).
 */
class HostelService
{
    // ==================================================================
    // Access
    // ==================================================================

    /** Hostel ids this user works in, by role; null = every hostel (admin). */
    public function hostelsFor(User $user, array $roles = ['manager', 'warden', 'house_parent', 'matron']): ?array
    {
        if (in_array($user->userProfile?->role, ['school_admin', 'super_admin'], true)) {
            return null;
        }

        return HostelStaffAssignment::withoutGlobalScopes()
            ->where('school_id', $user->userProfile?->school_id)
            ->where('user_id', $user->id)
            ->whereIn('role', $roles)
            ->where('starts_on', '<=', now()->toDateString())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', now()->toDateString()))
            ->pluck('hostel_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function assertHostel(User $user, int $hostelId, array $roles = ['manager', 'warden', 'house_parent', 'matron']): void
    {
        $ids = $this->hostelsFor($user, $roles);

        if ($ids !== null && ! in_array($hostelId, $ids, true)) {
            throw new AuthorisationFailure('You do not hold that responsibility in this hostel.');
        }
    }

    /** Welfare and incident detail: admins, hostel managers and matrons, and nurses. */
    public function seesWelfare(User $user, ?int $hostelId = null): bool
    {
        $role = $user->userProfile?->role;

        if (in_array($role, ['school_admin', 'super_admin', 'nurse'], true)) {
            return true;
        }

        $ids = $this->hostelsFor($user, ['manager', 'matron']);

        return $ids !== [] && ($hostelId === null || in_array($hostelId, $ids, true));
    }

    /** The hostel a student currently sleeps in, through their active allocation. */
    public function hostelOf(int $studentId): ?int
    {
        return DB::table('hostel_allocations as a')
            ->join('hostel_beds as b', 'b.id', '=', 'a.bed_id')
            ->join('hostel_rooms as r', 'r.id', '=', 'b.room_id')
            ->where('a.student_id', $studentId)->where('a.status', 'active')
            ->value('r.hostel_id');
    }

    // ==================================================================
    // Structure
    // ==================================================================

    /** A room and its beds, labelled B1…Bn. */
    public function createRoom(Hostel $hostel, array $data): HostelRoom
    {
        return DB::transaction(function () use ($hostel, $data) {
            $room = HostelRoom::create($data + ['school_id' => $hostel->school_id, 'hostel_id' => $hostel->id, 'status' => 'available']);

            for ($i = 1; $i <= (int) $data['capacity']; $i++) {
                HostelBed::create(['school_id' => $hostel->school_id, 'room_id' => $room->id, 'label' => "B{$i}", 'status' => 'available']);
            }

            return $room;
        });
    }

    /**
     * Occupancy per room. Physical capacity counts every bed; usable capacity
     * leaves out beds and rooms out of service (§5).
     */
    public function rooms(int $schoolId, ?array $hostelIds = null): Collection
    {
        $rooms = HostelRoom::withoutGlobalScopes()->where('school_id', $schoolId)
            ->when($hostelIds !== null, fn ($q) => $q->whereIn('hostel_id', $hostelIds ?: [0]))
            ->with('hostel:id,name')->orderBy('hostel_id')->orderBy('block')->orderBy('name')->get();

        $beds = HostelBed::withoutGlobalScopes()->whereIn('room_id', $rooms->pluck('id'))->get()->groupBy('room_id');
        $taken = HostelAllocation::withoutGlobalScopes()->whereIn('bed_id', $beds->flatten()->pluck('id'))->whereIn('status', ['active', 'reserved'])
            ->get(['bed_id', 'status', 'student_id'])->keyBy('bed_id');

        return $rooms->map(function (HostelRoom $room) use ($beds, $taken) {
            $roomBeds = $beds->get($room->id, collect());
            $usable = $room->status === 'out_of_service' ? 0 : $roomBeds->where('status', 'available')->count();
            $occupied = $roomBeds->filter(fn ($b) => ($taken[$b->id]->status ?? null) === 'active')->count();
            $reserved = $roomBeds->filter(fn ($b) => ($taken[$b->id]->status ?? null) === 'reserved')->count();

            return [
                'id' => $room->id,
                'hostel_id' => $room->hostel_id,
                'hostel' => $room->hostel?->name,
                'block' => $room->block,
                'floor' => $room->floor,
                'name' => $room->name,
                'capacity' => (int) $room->capacity,
                'physical_beds' => $roomBeds->count(),
                'usable_beds' => $usable,
                'occupied' => $occupied,
                'reserved' => $reserved,
                'available' => max(0, $usable - $occupied - $reserved),
                'status' => $room->status,
                'over_capacity' => $occupied > (int) $room->capacity,
                'beds' => $roomBeds->map(fn ($b) => [
                    'id' => $b->id, 'label' => $b->label, 'status' => $b->status,
                    'occupancy' => $taken[$b->id]->status ?? null,
                ])->values(),
            ];
        })->values();
    }

    // ==================================================================
    // Enrolment and allocation (§7, §8)
    // ==================================================================

    public function decideEnrollment(HostelEnrollment $e, User $by, string $decision, ?string $note): HostelEnrollment
    {
        if (! in_array($e->status, ['submitted', 'under_review', 'waitlisted'], true)) {
            throw new DomainRefusal('This enrolment has already been decided.');
        }

        $status = ['approve' => 'approved', 'reject' => 'rejected', 'waitlist' => 'waitlisted'][$decision];
        $e->update(['status' => $status, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'decision_note' => $note]);
        $this->audit($by, 'hostel.enrollment_'.$status, HostelEnrollment::class, $e->id, ['note' => $note]);

        return $e->fresh();
    }

    /**
     * Put a student in a bed. Refused: a bed out of service; a bed already
     * held for the period; a student who already has a bed; a full room —
     * unless an authorised override with a reason (§8, rules 1–5).
     */
    public function allocate(int $schoolId, int $studentId, HostelBed $bed, User $by, array $opts = []): HostelAllocation
    {
        $override = trim((string) ($opts['override_reason'] ?? ''));
        $isAdmin = $by->userProfile?->role === 'school_admin';

        return DB::transaction(function () use ($schoolId, $studentId, $bed, $by, $opts, $override, $isAdmin) {
            $bed = HostelBed::withoutGlobalScopes()->lockForUpdate()->findOrFail($bed->id);
            $room = HostelRoom::withoutGlobalScopes()->findOrFail($bed->room_id);

            $enrollment = $opts['enrollment_id'] ?? null
                ? HostelEnrollment::withoutGlobalScopes()->where('school_id', $schoolId)->findOrFail($opts['enrollment_id'])
                : HostelEnrollment::withoutGlobalScopes()->where('school_id', $schoolId)->where('student_id', $studentId)->whereIn('status', ['approved', 'active'])->latest('id')->first();

            if (! $enrollment && ! ($opts['legacy'] ?? false)) {
                throw new DomainRefusal('The student needs an approved hostel enrolment before a bed (rule 1). A waitlisted student does not hold a bed.');
            }

            if ($bed->status === 'out_of_service' || $room->status === 'out_of_service') {
                throw new DomainRefusal('That bed is out of service.');
            }

            if (HostelAllocation::withoutGlobalScopes()->where('bed_id', $bed->id)->whereIn('status', ['active', 'reserved'])->exists()) {
                throw new AllocationRefused('That bed is already allocated.', 'bed_number');
            }

            if (HostelAllocation::withoutGlobalScopes()->where('student_id', $studentId)->where('status', 'active')->exists()) {
                throw new AllocationRefused('That student already has a bed. Move them with a room change, which ends the current allocation.', 'student_id');
            }

            $occupied = HostelAllocation::withoutGlobalScopes()->whereIn('bed_id', HostelBed::withoutGlobalScopes()->where('room_id', $room->id)->pluck('id'))->whereIn('status', ['active', 'reserved'])->count();

            if ($occupied >= (int) $room->capacity) {
                if ($override === '' || ! $isAdmin) {
                    throw new AllocationRefused('That room is at its approved capacity. An admin can override with a recorded reason.', 'room_id');
                }
            }

            $allocation = HostelAllocation::create([
                'school_id' => $schoolId,
                'enrollment_id' => $enrollment?->id,
                'student_id' => $studentId,
                'bed_id' => $bed->id,
                'starts_on' => $opts['starts_on'] ?? now()->toDateString(),
                'status' => ($opts['reserve'] ?? false) ? 'reserved' : 'active',
                'reason' => $opts['reason'] ?? null,
                'override_reason' => $occupied >= (int) $room->capacity ? $override : null,
                'allocated_by' => $by->id,
            ]);

            if ($enrollment && $enrollment->status === 'approved') {
                $enrollment->update(['status' => 'active']);
            }

            $this->audit($by, 'hostel.allocated', HostelAllocation::class, $allocation->id, ['bed_id' => $bed->id, 'student_id' => $studentId, 'override' => $override ?: null]);

            return $allocation;
        });
    }

    /** End the current allocation and start a new one (rule 6). */
    public function move(HostelAllocation $current, HostelBed $to, User $by, string $reason): HostelAllocation
    {
        if ($current->status !== 'active') {
            throw new DomainRefusal('Only an active allocation can be moved.');
        }

        return DB::transaction(function () use ($current, $to, $by, $reason) {
            $this->endAllocation($current, $by, "Moved: {$reason}");

            return $this->allocate($current->school_id, $current->student_id, $to, $by, ['enrollment_id' => $current->enrollment_id, 'reason' => $reason, 'legacy' => $current->enrollment_id === null]);
        });
    }

    public function endAllocation(HostelAllocation $allocation, User $by, string $reason): void
    {
        $allocation->update(['status' => 'ended', 'ends_on' => now()->toDateString(), 'ended_by' => $by->id, 'end_reason' => $reason]);
        $this->audit($by, 'hostel.allocation_ended', HostelAllocation::class, $allocation->id, ['reason' => $reason]);
    }

    // ==================================================================
    // Check-in and check-out (§9)
    // ==================================================================

    public function checkIn(int $schoolId, int $studentId, User $by, array $checklist = [], ?string $notes = null): HostelMovement
    {
        if (! HostelAllocation::withoutGlobalScopes()->where('student_id', $studentId)->whereIn('status', ['active', 'reserved'])->exists()) {
            throw new DomainRefusal('Allocate a bed before checking the student in.');
        }

        HostelAllocation::withoutGlobalScopes()->where('student_id', $studentId)->where('status', 'reserved')->update(['status' => 'active']);

        return $this->movement($schoolId, $studentId, 'check_in', $by, ['checklist' => $checklist, 'notes' => $notes]);
    }

    /**
     * Temporary (going home for the holiday) or ending the stay. The bed is
     * released only when the stay ends, never on a temporary checkout.
     */
    public function checkOut(int $schoolId, int $studentId, User $by, bool $endsStay, ?string $collectedBy, array $checklist = [], ?string $notes = null): HostelMovement
    {
        return DB::transaction(function () use ($schoolId, $studentId, $by, $endsStay, $collectedBy, $checklist, $notes) {
            $movement = $this->movement($schoolId, $studentId, 'check_out', $by, ['ends_stay' => $endsStay, 'collected_by' => $collectedBy, 'checklist' => $checklist, 'notes' => $notes]);

            if ($endsStay) {
                foreach (HostelAllocation::withoutGlobalScopes()->where('student_id', $studentId)->where('status', 'active')->get() as $a) {
                    $this->endAllocation($a, $by, 'Checked out: stay ended');
                }

                HostelEnrollment::withoutGlobalScopes()->where('student_id', $studentId)->where('status', 'active')->update(['status' => 'completed', 'ends_on' => now()->toDateString()]);
            }

            return $movement;
        });
    }

    public function movement(int $schoolId, int $studentId, string $type, User $by, array $extra = []): HostelMovement
    {
        return HostelMovement::withoutGlobalScopes()->create([
            'school_id' => $schoolId,
            'student_id' => $studentId,
            'type' => $type,
            'occurred_at' => $extra['occurred_at'] ?? now(),
            'leave_request_id' => $extra['leave_request_id'] ?? null,
            'collected_by' => $extra['collected_by'] ?? null,
            'checklist' => $extra['checklist'] ?? null,
            'ends_stay' => (bool) ($extra['ends_stay'] ?? false),
            'notes' => $extra['notes'] ?? null,
            'corrects_id' => $extra['corrects_id'] ?? null,
            'correction_reason' => $extra['correction_reason'] ?? null,
            'recorded_by' => $by->id,
        ]);
    }

    /** Where a boarder is right now, from events: in, out on leave, or away. */
    public function presence(int $studentId): string
    {
        $last = HostelMovement::withoutGlobalScopes()->where('student_id', $studentId)->whereNull('corrects_id')
            ->orderByDesc('occurred_at')->orderByDesc('id')->first();

        return match ($last?->type) {
            'check_in', 'return' => 'in_hostel',
            'departure' => 'on_leave',
            'check_out' => 'checked_out',
            default => 'not_arrived',
        };
    }

    /** Approved leave whose return time (plus grace) has passed with no return recorded (rule 12). */
    public function overdue(int $schoolId): Collection
    {
        $grace = HostelSetting::for($schoolId)->return_grace_minutes;

        return HostelLeaveRequest::withoutGlobalScopes()->where('school_id', $schoolId)->where('status', 'departed')
            ->where('return_at', '<', now()->subMinutes($grace))
            ->with('student.user:id,name')->get();
    }

    // ==================================================================
    // Charges through Finance (§19)
    // ==================================================================

    /**
     * Charges for every boarder with an active enrolment, from the school's
     * fee rules. Each charge is keyed so the same thing is never billed
     * twice; posting to Finance adds an item to the student's term invoice.
     *
     * @return array{created: int, posted: int, awaiting_invoice: int}
     */
    public function bill(int $schoolId, int $termId, User $by): array
    {
        $rules = HostelFeeRule::withoutGlobalScopes()->where('school_id', $schoolId)->where('is_active', true)->get()->groupBy('boarding_category');
        $enrolments = HostelEnrollment::withoutGlobalScopes()->where('school_id', $schoolId)->where('status', 'active')->get();
        $created = 0;
        $posted = 0;
        $waiting = 0;

        DB::transaction(function () use ($rules, $enrolments, $schoolId, $termId, $by, &$created, &$posted, &$waiting) {
            foreach ($enrolments as $e) {
                foreach ($rules->get($e->boarding_category, collect()) as $rule) {
                    $key = "term:{$termId}:student:{$e->student_id}:rule:{$rule->id}";

                    $charge = HostelCharge::withoutGlobalScopes()->firstOrCreate(
                        ['school_id' => $schoolId, 'dedupe_key' => $key],
                        ['student_id' => $e->student_id, 'term_id' => $termId, 'charge_type' => $rule->charge_type, 'title' => $rule->title, 'amount' => $rule->amount, 'status' => 'pending', 'created_by' => $by->id]
                    );

                    if ($charge->wasRecentlyCreated) {
                        $created++;
                    }

                    if ($charge->status !== 'pending') {
                        continue;
                    }

                    $invoice = Invoice::withoutGlobalScopes()->where('school_id', $schoolId)->where('student_id', $e->student_id)->where('term_id', $termId)->first();

                    if (! $invoice) {
                        $waiting++;

                        continue;
                    }

                    $item = InvoiceItem::withoutGlobalScopes()->create(['school_id' => $schoolId, 'invoice_id' => $invoice->id, 'title' => "Hostel: {$charge->title}", 'amount' => $charge->amount]);
                    $invoice->update(['total_amount' => (float) $invoice->total_amount + (float) $charge->amount, 'status' => (float) $invoice->amount_paid > 0 ? 'partial' : 'unpaid']);
                    $charge->update(['status' => 'posted', 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id]);
                    $posted++;
                }
            }
        });

        $this->audit($by, 'hostel.billed', HostelCharge::class, 0, ['term_id' => $termId, 'created' => $created, 'posted' => $posted, 'awaiting_invoice' => $waiting]);

        return ['created' => $created, 'posted' => $posted, 'awaiting_invoice' => $waiting];
    }

    // ==================================================================

    public function audit(User $by, string $action, string $type, int $id, array $values): void
    {
        AuditLog::create(['school_id' => $by->userProfile?->school_id, 'user_id' => $by->id, 'action' => $action, 'auditable_type' => $type, 'auditable_id' => $id, 'new_values' => $values]);
    }
}
