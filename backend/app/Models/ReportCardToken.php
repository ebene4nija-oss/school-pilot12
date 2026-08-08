<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The QR code printed on a report card.
 *
 * Deliberately its own table rather than a column on `score_entries`: a report
 * card is one document covering every subject for a term, so the thing being
 * verified is (student, term) — not a single subject row. The previous code
 * looked up a `score_entries.verification_token` column that no migration ever
 * created, so no printed card could ever be verified.
 *
 * The token is random, not derived. An `md5(student_id + term_id + APP_KEY)`
 * scheme lets anyone who learns the key mint a valid code for any child, and
 * gives no way to invalidate a card that was issued in error.
 */
class ReportCardToken extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'report_card_tokens';

    protected $fillable = [
        'school_id',
        'student_id',
        'term_id',
        'qr_token',
        'is_valid',
    ];

    protected $casts = [
        'is_valid' => 'boolean',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The live token for a student's term, minting one on first print.
     *
     * Reprinting the same card reuses the token so a parent holding last
     * week's copy can still verify it. Revoking (`is_valid = false`) and
     * calling this again issues a fresh one, which is how a card withdrawn
     * after a marking correction stops verifying.
     */
    public static function issueFor(int $schoolId, int $studentId, int $termId): self
    {
        $existing = static::withoutGlobalScopes()
            ->where('student_id', $studentId)
            ->where('term_id', $termId)
            ->where('is_valid', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        return static::create([
            'school_id' => $schoolId,
            'student_id' => $studentId,
            'term_id' => $termId,
            // 40 chars of URL-safe randomness: long enough that the public
            // verification endpoint cannot be walked, short enough to survive
            // being printed into a QR code at report-card size.
            'qr_token' => Str::random(40),
            'is_valid' => true,
        ]);
    }
}
