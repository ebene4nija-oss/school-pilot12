<?php

namespace App\Services\IdCard;

use App\Exceptions\DomainRefusal;
use App\Models\IdCard;
use App\Models\IdCardPrintRun;
use App\Models\IdCardSetting;
use App\Models\Staff;
use App\Models\Student;
use App\Support\InAppNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Production and issuance for ID cards (TASK 18 §11–§14), alongside the
 * validity rules IdCardIssuanceService already owns.
 *
 * Generated, print file made, physically printed and handed over are four
 * separate facts, each recorded when it happens and never inferred: a PDF
 * download does not print anything, and a printed card in a drawer has not
 * been issued.
 */
class IdCardLifecycleService
{
    public function event(IdCard $card, string $event, ?string $from, ?string $to, ?string $note = null, ?int $userId = null): void
    {
        DB::table('id_card_status_events')->insert([
            'school_id' => $card->school_id, 'card_id' => $card->id, 'event' => $event,
            'from' => $from, 'to' => $to, 'note' => $note ? mb_substr($note, 0, 255) : null, 'user_id' => $userId, 'created_at' => now(),
        ]);
    }

    /** A print file now holds these cards. Already-printed cards are reprints, recorded as such. */
    public function printFileMade(array $cardIds, IdCardPrintRun $run): void
    {
        foreach (IdCard::withoutGlobalScopes()->whereIn('id', $cardIds)->get() as $card) {
            if ($card->production_status === 'generated') {
                $card->update(['production_status' => 'print_ready']);
                $this->event($card, 'print_file_made', 'generated', 'print_ready', "Print run {$run->id}", $run->requested_by);
            } else {
                $this->event($card, 'reprint_file_made', $card->production_status, $card->production_status, "Print run {$run->id}", $run->requested_by);
            }
        }
    }

    /**
     * Someone has the printed sheets in hand. Misprints stay print-ready for
     * the next run, so a smudged card is reprinted rather than lost.
     *
     * @return array{printed: int, misprinted: int}
     */
    public function confirmPrinted(IdCardPrintRun $run, int $userId, array $misprintedIds = []): array
    {
        if ($run->status !== 'completed') {
            throw new DomainRefusal('This run has no print file yet.');
        }
        if ($run->physical_status === 'printed') {
            throw new DomainRefusal('This run was already confirmed as printed.');
        }

        $ids = array_map('intval', (array) ($run->preflight_report['issued_card_ids'] ?? []));
        $misprintedIds = array_values(array_intersect(array_map('intval', $misprintedIds), $ids));
        $printed = 0;

        DB::transaction(function () use ($run, $ids, $misprintedIds, $userId, &$printed) {
            foreach (IdCard::withoutGlobalScopes()->whereIn('id', $ids)->get() as $card) {
                if (in_array($card->id, $misprintedIds, true)) {
                    $card->update(['misprints' => $card->misprints + 1]);
                    $this->event($card, 'misprinted', $card->production_status, $card->production_status, "Print run {$run->id}", $userId);
                    continue;
                }
                if ($card->production_status === 'print_ready') {
                    $card->update(['production_status' => 'printed', 'printed_at' => now()]);
                    $this->event($card, 'printed', 'print_ready', 'printed', "Print run {$run->id}", $userId);
                    $printed++;
                }
            }
            $run->update(['physical_status' => 'printed', 'confirmed_by' => $userId, 'confirmed_at' => now(), 'misprinted_card_ids' => $misprintedIds]);
        });

        $this->notifyReady(IdCard::withoutGlobalScopes()->whereIn('id', array_diff($ids, $misprintedIds))->where('production_status', 'printed')->get());

        return ['printed' => $printed, 'misprinted' => count($misprintedIds)];
    }

    /** Handed to the holder, or to whoever collected it for them (§12). */
    public function handOver(IdCard $card, int $userId, ?string $collectedBy): IdCard
    {
        if ($card->production_status !== 'printed') {
            throw new DomainRefusal($card->production_status === 'issued' ? 'This card has already been handed over.' : 'Only a printed card can be handed over. Confirm the print first.');
        }
        if ($card->status !== 'active') {
            throw new DomainRefusal('This card is no longer valid and cannot be handed over.');
        }

        $card->update(['production_status' => 'issued', 'handed_over_at' => now(), 'handed_over_by' => $userId, 'collected_by' => $collectedBy ?: 'Holder']);
        $this->event($card, 'handed_over', 'printed', 'issued', 'Collected by '.($collectedBy ?: 'the holder'), $userId);

        return $card->fresh();
    }

    /** What a verifier is told, with the school's issuance rule applied. */
    public function verificationStatus(IdCard $card): string
    {
        $status = $card->effectiveStatus();
        if ($status === 'active' && $card->production_status !== 'issued' && IdCardSetting::for((int) $card->school_id)->valid_only_when_issued) {
            return 'not_issued';
        }

        return $status;
    }

    public function logVerification(?IdCard $card, string $outcome, ?int $userId, ?int $schoolId = null): void
    {
        $schoolId = $card?->school_id ?? $schoolId;
        if ($schoolId && ! IdCardSetting::for((int) $schoolId)->log_verifications) {
            return;
        }
        DB::table('id_card_verification_logs')->insert(['school_id' => $schoolId, 'card_id' => $card?->id, 'outcome' => $outcome, 'user_id' => $userId, 'created_at' => now()]);
    }

    /** Leaving the school ends the card (§22.14–15). */
    public function holderLeft(string $holderType, int $holderId, int $schoolId, ?int $userId = null): int
    {
        $count = 0;
        $issuance = app(IdCardIssuanceService::class);
        foreach (IdCard::withoutGlobalScopes()->where('school_id', $schoolId)->forHolder($holderType, $holderId)->where('status', 'active')->get() as $card) {
            $issuance->revoke($card, 'left_school', $userId);
            $this->event($card, 'deactivated', 'active', 'revoked', 'Holder left the school', $userId);
            $count++;
        }

        return $count;
    }

    /**
     * Production and validity counts for the dashboard (§4).
     *
     * @return array<string, int>
     */
    public function summary(int $schoolId, string $holderType, ?int $classId = null): array
    {
        $settings = IdCardSetting::for($schoolId);
        $eligible = $holderType === 'student'
            ? Student::withoutGlobalScopes()->where('school_id', $schoolId)->where('status', 'active')->when($classId, fn ($q) => $q->where('class_id', $classId))->pluck('id')
            : Staff::withoutGlobalScopes()->where('school_id', $schoolId)->pluck('id');

        $cards = IdCard::withoutGlobalScopes()->where('school_id', $schoolId)->where('holder_type', $holderType)->whereIn('holder_id', $eligible->all() ?: [0])->get();
        $valid = $cards->filter(fn ($c) => $c->isValid());
        $warnBy = Carbon::today()->addDays($settings->expiry_warning_days);
        $replaced = $cards->pluck('replaces_id')->filter()->all();

        return [
            'eligible' => $eligible->count(),
            'not_generated' => $eligible->diff($valid->pluck('holder_id'))->count(),
            'generated' => $valid->count(),
            'pending_print' => $valid->whereIn('production_status', ['generated', 'print_ready'])->count(),
            'printed' => $valid->whereIn('production_status', ['printed', 'issued'])->count(),
            'pending_collection' => $valid->where('production_status', 'printed')->count(),
            'issued' => $valid->where('production_status', 'issued')->count(),
            'expiring_soon' => $valid->filter(fn ($c) => $c->expires_on && $c->expires_on->lte($warnBy))->count(),
            'lost_replacement_pending' => $cards->filter(fn ($c) => $c->status === 'revoked' && in_array($c->revoked_reason, ['lost', 'stolen', 'damaged'], true) && ! in_array($c->id, $replaced, true))->count(),
            'deactivated' => $cards->where('status', 'revoked')->count(),
            'expired' => $cards->filter(fn ($c) => $c->effectiveStatus() === 'expired')->count(),
        ];
    }

    private function notifyReady($cards): void
    {
        foreach ($cards->groupBy('school_id') as $schoolId => $group) {
            $students = Student::withoutGlobalScopes()->whereIn('id', $group->where('holder_type', 'student')->pluck('holder_id'))->get();
            $staff = Staff::withoutGlobalScopes()->whereIn('id', $group->where('holder_type', 'staff')->pluck('holder_id'))->get();
            $users = $students->pluck('user_id')->merge($staff->pluck('user_id'))->map(fn ($v) => (int) $v)->all();
            InAppNotifier::send((int) $schoolId, $users, 'id_cards', 'ID card ready', 'Your ID card is ready for collection from the school office.');
        }
    }
}
