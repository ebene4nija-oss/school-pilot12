<?php

namespace App\Services\IdCard;

use App\Models\AcademicSession;
use App\Models\IdCard;
use App\Models\School;
use App\Models\Staff;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The lifecycle of an issued card: minting, revoking, replacing, expiring.
 *
 * Separate from the rendering side on purpose. Issuing a card and printing one
 * are different acts that happen at different times — a card is issued once
 * and may be printed three times (the first sheet jammed, the child lost it in
 * week two, the school re-prints the class in January because four children
 * joined). Conflating them is how a system ends up minting a new identity
 * every time somebody presses print, which quietly invalidates the card
 * already in the child's bag.
 */
class IdCardIssuanceService
{
    /** Attempts to win a serial race before giving up. */
    private const SERIAL_ATTEMPTS = 5;

    /**
     * The card a holder currently carries, if any.
     */
    public function currentCardFor(int $schoolId, string $holderType, int $holderId): ?IdCard
    {
        return IdCard::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->forHolder($holderType, $holderId)
            ->valid()
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Issue a card, or hand back the one the holder already has.
     *
     * Reuse is the default and matters more than it looks. A school printing
     * "JSS 2" a second time to catch four new arrivals must not mint 42 new
     * serials and silently orphan the cards the other 38 children are already
     * carrying — the QR on those would keep working, but the register would no
     * longer agree with them.
     *
     * @param array{session_id?:int|null,expires_on?:string|null,issued_by?:int|null,force_new?:bool} $options
     */
    public function issue(int $schoolId, string $holderType, int $holderId, array $options = []): IdCard
    {
        if (!in_array($holderType, IdCard::HOLDER_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown ID card holder type \"{$holderType}\".");
        }

        if (empty($options['force_new'])) {
            $existing = $this->currentCardFor($schoolId, $holderType, $holderId);

            if ($existing) {
                return $existing;
            }
        }

        $session = $this->resolveSession($schoolId, $options['session_id'] ?? null);

        return $this->createCard($schoolId, $holderType, $holderId, [
            'session_id' => $session?->id,
            'issued_on' => Carbon::today(),
            'expires_on' => $this->resolveExpiry($holderType, $session, $options['expires_on'] ?? null),
            'issued_by' => $options['issued_by'] ?? null,
            'replaces_id' => $options['replaces_id'] ?? null,
        ]);
    }

    /**
     * Withdraw a card from circulation.
     *
     * Nothing is deleted. The row is the answer to "someone handed me this
     * card at the gate, is it good?", and that question keeps being asked
     * about cards the school has already written off — which is exactly when
     * the answer matters most.
     */
    public function revoke(IdCard $card, string $reason, ?int $userId = null): IdCard
    {
        if (!in_array($reason, IdCard::REVOCATION_REASONS, true)) {
            $reason = 'other';
        }

        $card->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_reason' => $reason,
            'revoked_by' => $userId,
        ]);

        return $card->fresh();
    }

    /**
     * Revoke a card and issue its successor in one step.
     *
     * The replacement carries `replaces_id`, so the chain behind a child on
     * their fourth card is walkable — which is the honest way to answer "how
     * many replacements has this student had?" when a school charges for them.
     */
    public function replace(IdCard $card, string $reason, ?int $userId = null): IdCard
    {
        $this->revoke($card, $reason, $userId);

        return $this->issue($card->school_id, $card->holder_type, $card->holder_id, [
            'session_id' => $card->session_id,
            'issued_by' => $userId,
            'replaces_id' => $card->id,
            'force_new' => true,
        ]);
    }

    /**
     * Write down expiry that has already happened in fact.
     *
     * IdCard::effectiveStatus() means nothing depends on this having run —
     * an expired card reads as expired the moment its date passes, sweep or no
     * sweep. This exists so that admin listings and counts agree with reality
     * without every query having to reason about dates.
     *
     * @return int rows updated
     */
    public function expireStale(?int $schoolId = null): int
    {
        $query = IdCard::withoutGlobalScopes()
            ->where('status', 'active')
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', Carbon::today());

        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }

        return $query->update(['status' => 'expired']);
    }

    /**
     * Resolve a Student or Staff row, tenant-bound.
     */
    public function resolveHolder(int $schoolId, string $holderType, int $holderId): ?Model
    {
        return match ($holderType) {
            'student' => Student::withoutGlobalScopes()
                ->with(['user', 'currentClass', 'currentArm'])
                ->where('school_id', $schoolId)
                ->find($holderId),
            'staff' => Staff::withoutGlobalScopes()
                ->with('user')
                ->where('school_id', $schoolId)
                ->find($holderId),
            default => null,
        };
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function createCard(int $schoolId, string $holderType, int $holderId, array $attributes): IdCard
    {
        /*
         * Serials are sequential per school, so two admins printing two
         * classes at the same moment can collide. Rather than lock the table
         * for the duration of a 400-card run, mint optimistically and retry on
         * the unique constraint — collisions are rare and a retry is cheap.
         */
        $lastError = null;

        for ($attempt = 0; $attempt < self::SERIAL_ATTEMPTS; $attempt++) {
            try {
                return IdCard::create(array_merge($attributes, [
                    'school_id' => $schoolId,
                    'holder_type' => $holderType,
                    'holder_id' => $holderId,
                    'serial' => $this->mintSerial($schoolId, $holderType),
                    // 40 chars of URL-safe randomness, the same size as a
                    // report card token: too long to walk, short enough to
                    // survive being printed into a 15mm QR code.
                    'verify_token' => Str::random(40),
                    'status' => 'active',
                    'print_count' => 0,
                ]));
            } catch (QueryException $e) {
                if (!$this->isUniqueViolation($e)) {
                    throw $e;
                }

                $lastError = $e;
            }
        }

        throw new \RuntimeException(
            'Could not allocate an ID card serial after ' . self::SERIAL_ATTEMPTS . ' attempts.',
            0,
            $lastError
        );
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23505 is Postgres, 23000 covers MySQL/SQLite integrity violations.
        return in_array((string) $e->getCode(), ['23505', '23000'], true);
    }

    /**
     * `GC/STU/2026/0187` — readable over the phone.
     *
     * A bursar taking a call about a found card reads the serial out; whoever
     * is at the other end needs to be able to type it into a search box
     * without asking how to spell a UUID. The sequence is per school, holder
     * type and year, so it stays short for the life of the product.
     */
    private function mintSerial(int $schoolId, string $holderType): string
    {
        $prefix = $this->schoolPrefix($schoolId);
        $kind = $holderType === 'staff' ? 'STF' : 'STU';
        $year = Carbon::today()->year;

        $like = "{$prefix}/{$kind}/{$year}/%";

        $last = IdCard::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('serial', 'like', $like)
            ->orderByDesc('serial')
            ->value('serial');

        $next = 1;

        if ($last && preg_match('#/(\d+)$#', $last, $match)) {
            $next = (int) $match[1] + 1;
        }

        return sprintf('%s/%s/%d/%04d', $prefix, $kind, $year, $next);
    }

    /**
     * A short, stable, alphanumeric stand-in for the school.
     *
     * Taken from the subdomain, which is already unique per tenant and already
     * printed on everything else the school issues. Falls back to the initials
     * of the name, then to the id — a school always gets *some* prefix, because
     * a serial that fails to mint blocks a print run.
     */
    private function schoolPrefix(int $schoolId): string
    {
        $school = School::find($schoolId);

        $candidate = (string) ($school->subdomain ?? '');

        if ($candidate === '' && $school) {
            $words = preg_split('/\s+/', trim((string) $school->name)) ?: [];
            $candidate = implode('', array_map(fn ($word) => mb_substr($word, 0, 1), array_filter($words)));
        }

        $candidate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $candidate) ?? '');

        /*
         * Ten characters, not six. A tighter cap saves nothing on a card that
         * has room for the whole serial, and it turns "graceland" into
         * "GRACEL" — which looks like a typo to the parent reading it and
         * defeats the point of a serial a human can recognise.
         */
        return $candidate !== '' ? mb_substr($candidate, 0, 10) : 'SCH' . $schoolId;
    }

    private function resolveSession(int $schoolId, ?int $sessionId): ?AcademicSession
    {
        $query = AcademicSession::withoutGlobalScopes()->where('school_id', $schoolId);

        return $sessionId
            ? $query->find($sessionId)
            : $query->where('is_current', true)->first();
    }

    /**
     * When the card stops being valid.
     *
     * A student card is cut to the end of the session: a child's class, and
     * therefore most of what is printed on the card, changes in September, and
     * a card that outlives its own contents is worse than no card. A staff
     * card has no natural end, so it runs until revoked — which the
     * verification page states in words rather than showing a blank field.
     */
    private function resolveExpiry(string $holderType, ?AcademicSession $session, ?string $override): ?Carbon
    {
        if ($override) {
            return Carbon::parse($override);
        }

        if ($holderType === 'staff') {
            return null;
        }

        if ($session && $session->end_date) {
            return Carbon::parse($session->end_date);
        }

        return null;
    }
}
