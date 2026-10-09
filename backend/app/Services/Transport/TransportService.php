<?php

namespace App\Services\Transport;

use App\Exceptions\DomainRefusal;
use App\Models\AuditLog;
use App\Models\BusDocument;
use App\Models\BusMaintenanceLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TransportCharge;
use App\Models\TransportRider;
use App\Models\TransportSetting;
use App\Models\User;
use App\Support\InAppNotifier;
use Illuminate\Support\Facades\DB;

/**
 * The school bus: billing the students who ride, and keeping the buses on
 * the road.
 *
 * Money rule: one flat termly fee per rider (TransportSetting), added to the
 * rider's term invoice. Students who don't ride are never billed. Bus costs
 * stay in transport and are not posted to the school's expenses.
 */
class TransportService
{
    /**
     * Charge every active rider the bus fee for a term. Safe to run again:
     * a student is charged once per term, and a charge waits (pending) for
     * the student's term invoice if Finance hasn't generated it yet.
     *
     * @return array{created: int, posted: int, awaiting_invoice: int}
     */
    public function bill(int $schoolId, int $termId, User $by): array
    {
        $fee = (float) TransportSetting::for($schoolId)->termly_fee;
        if ($fee <= 0) {
            throw new DomainRefusal('Set the termly bus fee first.');
        }

        $created = 0;
        $posted = 0;
        $waiting = 0;

        DB::transaction(function () use ($schoolId, $termId, $fee, $by, &$created, &$posted, &$waiting) {
            $riders = TransportRider::withoutGlobalScopes()->where('school_id', $schoolId)->whereNull('ended_on')->pluck('student_id')->unique();

            foreach ($riders as $studentId) {
                $charge = TransportCharge::withoutGlobalScopes()->firstOrCreate(
                    ['school_id' => $schoolId, 'term_id' => $termId, 'student_id' => $studentId],
                    ['amount' => $fee, 'status' => 'pending', 'created_by' => $by->id]
                );
                if ($charge->wasRecentlyCreated) {
                    $created++;
                }
                if ($charge->status !== 'pending') {
                    continue;
                }

                $invoice = Invoice::withoutGlobalScopes()->where('school_id', $schoolId)->where('student_id', $studentId)->where('term_id', $termId)->lockForUpdate()->first();
                if (! $invoice) {
                    $waiting++;

                    continue;
                }

                $item = InvoiceItem::withoutGlobalScopes()->create(['school_id' => $schoolId, 'invoice_id' => $invoice->id, 'title' => 'School bus', 'amount' => $charge->amount]);
                $invoice->update(['total_amount' => (float) $invoice->total_amount + (float) $charge->amount, 'status' => (float) $invoice->amount_paid > 0 ? 'partial' : 'unpaid']);
                $charge->update(['status' => 'posted', 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id]);
                $posted++;
            }
        });

        $this->audit($by, $schoolId, 'transport.billed', ['term_id' => $termId, 'created' => $created, 'posted' => $posted, 'awaiting_invoice' => $waiting]);

        return ['created' => $created, 'posted' => $posted, 'awaiting_invoice' => $waiting];
    }

    /**
     * A breakdown takes the bus off the road; the parents of its riders can
     * be told, so nobody waits at a pickup point for a bus that isn't coming.
     */
    public function breakdown(int $schoolId, int $busId, BusMaintenanceLog $log, bool $tellParents): int
    {
        DB::table('buses')->where('school_id', $schoolId)->where('id', $busId)->update(['status' => 'off_road', 'updated_at' => now()]);

        if (! $tellParents) {
            return 0;
        }

        $busNumber = (string) DB::table('buses')->where('id', $busId)->value('bus_number');
        $parents = DB::table('transport_riders')->join('student_guardian', 'student_guardian.student_id', '=', 'transport_riders.student_id')
            ->join('guardians', 'guardians.id', '=', 'student_guardian.guardian_id')
            ->where('transport_riders.school_id', $schoolId)->where('transport_riders.bus_id', $busId)->whereNull('transport_riders.ended_on')
            ->pluck('guardians.user_id')->filter()->unique()->values()->all();

        InAppNotifier::send($schoolId, $parents, 'transport', 'School bus off the road', "Bus {$busNumber} is out of service for now. Please arrange another way to and from school until the school says it is back.", true);

        return count($parents);
    }

    public function backInService(int $schoolId, int $busId, bool $tellParents): void
    {
        DB::transaction(function () use ($schoolId, $busId) {
            DB::table('buses')->where('school_id', $schoolId)->where('id', $busId)->update(['status' => 'in_service', 'updated_at' => now()]);
            BusMaintenanceLog::withoutGlobalScopes()->where('school_id', $schoolId)->where('bus_id', $busId)
                ->where('kind', 'breakdown')->whereNull('back_in_service_at')->update(['back_in_service_at' => now()]);
        });

        if ($tellParents) {
            $busNumber = (string) DB::table('buses')->where('id', $busId)->value('bus_number');
            $parents = DB::table('transport_riders')->join('student_guardian', 'student_guardian.student_id', '=', 'transport_riders.student_id')
                ->join('guardians', 'guardians.id', '=', 'student_guardian.guardian_id')
                ->where('transport_riders.school_id', $schoolId)->where('transport_riders.bus_id', $busId)->whereNull('transport_riders.ended_on')
                ->pluck('guardians.user_id')->filter()->unique()->values()->all();
            InAppNotifier::send($schoolId, $parents, 'transport', 'School bus back in service', "Bus {$busNumber} is running again.", true);
        }
    }

    /**
     * The morning check: papers about to expire, and services coming due.
     * Each reminder is sent once, to the school's admins.
     *
     * @return array{documents: int, services: int}
     */
    public function dailyReminders(): array
    {
        $documents = 0;
        $services = 0;
        $today = now()->startOfDay();

        BusDocument::withoutGlobalScopes()->whereNull('reminded_on')->orderBy('school_id')->get()
            ->each(function (BusDocument $doc) use ($today, &$documents) {
                $window = TransportSetting::for($doc->school_id)->renewal_warning_days;
                if ($doc->expires_on->gt($today->copy()->addDays($window))) {
                    return;
                }
                $bus = (string) DB::table('buses')->where('id', $doc->bus_id)->value('bus_number');
                $what = str_replace('_', ' ', $doc->kind);
                $when = $doc->expires_on->lt($today) ? 'expired on '.$doc->expires_on->format('j M') : 'expires on '.$doc->expires_on->format('j M');
                InAppNotifier::send($doc->school_id, $this->admins($doc->school_id), 'transport', 'Bus papers due', "Bus {$bus}: the {$what} {$when}. Renew it in Transport.");
                $doc->update(['reminded_on' => $today]);
                $documents++;
            });

        // The latest service on each bus says when the next is due.
        BusMaintenanceLog::withoutGlobalScopes()->where('kind', 'service')->whereNull('reminded_on')
            ->where(fn ($q) => $q->whereNotNull('next_service_on')->orWhereNotNull('next_service_km'))
            ->orderByDesc('logged_on')->get()->unique('bus_id')
            ->each(function (BusMaintenanceLog $log) use ($today, &$services) {
                $bus = DB::table('buses')->where('id', $log->bus_id)->first(['bus_number', 'odometer_km']);
                $byDate = $log->next_service_on && $log->next_service_on->lte($today->copy()->addDays(7));
                $byKm = $log->next_service_km && $bus?->odometer_km && (int) $bus->odometer_km >= $log->next_service_km - 500;
                if (! $byDate && ! $byKm) {
                    return;
                }
                $due = $byDate ? 'by '.$log->next_service_on->format('j M') : 'at '.number_format($log->next_service_km).' km';
                InAppNotifier::send($log->school_id, $this->admins($log->school_id), 'transport', 'Bus service due', "Bus {$bus?->bus_number} is due a service {$due}.");
                $log->update(['reminded_on' => $today]);
                $services++;
            });

        return ['documents' => $documents, 'services' => $services];
    }

    /** @return array<int, int> */
    private function admins(int $schoolId): array
    {
        return DB::table('user_profiles')->where('school_id', $schoolId)->where('role', 'school_admin')->pluck('user_id')->all();
    }

    private function audit(User $by, int $schoolId, string $action, array $values): void
    {
        AuditLog::create(['school_id' => $schoolId, 'user_id' => $by->id, 'action' => $action, 'auditable_type' => TransportCharge::class, 'auditable_id' => 0, 'new_values' => $values]);
    }
}
