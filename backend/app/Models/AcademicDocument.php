<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An official document as issued: transcript, transfer certificate and so on.
 *
 * The payload is what was printed. Its hash goes on the document's QR code,
 * so a document altered after issue fails verification. Only revocation and
 * replacement may be recorded later.
 */
class AcademicDocument extends Model
{
    use BelongsToTenant;

    public const TYPES = [
        'transcript' => 'Academic transcript',
        'transfer_certificate' => 'Transfer certificate',
        'transfer_summary' => 'Transfer academic summary',
        'attendance_summary' => 'Attendance summary',
        'conduct_reference' => "Principal's conduct reference",
        'clearance_certificate' => 'Clearance certificate',
        'subject_history' => 'Subject performance history',
    ];

    private const MUTABLE = ['revoked_at', 'revoked_reason', 'replaced_by_id', 'updated_at'];

    protected $fillable = [
        'school_id', 'student_id', 'transfer_id', 'type', 'reference', 'payload', 'payload_hash',
        'signature', 'issued_by', 'signatory_name', 'issued_at', 'revoked_at', 'revoked_reason', 'replaced_by_id',
    ];

    protected $casts = ['issued_at' => 'datetime', 'revoked_at' => 'datetime'];

    protected $hidden = ['payload', 'signature'];

    protected static function booted(): void
    {
        static::updating(function (AcademicDocument $doc) {
            if (array_diff(array_keys($doc->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('An issued document cannot be edited. Revoke it and issue a replacement.');
            }
        });

        static::deleting(fn () => throw new LogicException('Issued documents are never deleted.'));
    }

    public function content(): array
    {
        return json_decode($this->payload, true) ?? [];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
