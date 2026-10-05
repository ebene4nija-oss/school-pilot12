<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdmissionSetting extends Model
{
    use BelongsToTenant;

    public const DOCUMENTS = [
        'birth_certificate' => 'Birth certificate or declaration of age',
        'previous_report' => 'Last report card from previous school',
        'transfer_letter' => 'Transfer letter or testimonial',
        'passport_photo' => 'Passport photograph',
    ];

    public const DEFAULT_DOCUMENTS = [
        'nursery' => ['birth_certificate', 'passport_photo'],
        'primary' => ['birth_certificate', 'previous_report', 'passport_photo'],
        'secondary' => ['birth_certificate', 'previous_report', 'transfer_letter', 'passport_photo'],
    ];

    protected $fillable = [
        'school_id', 'public_form_enabled', 'open_sections', 'required_documents', 'retention_days', 'offer_validity_days', 'offer_letter_note',
        'admissions_status', 'admission_session', 'instructions', 'closed_message',
        'whatsapp_enabled', 'whatsapp_number', 'whatsapp_message',
        'email_enabled', 'admissions_email', 'email_subject',
        'phone_enabled', 'admissions_phone',
    ];

    protected $casts = [
        'public_form_enabled' => 'boolean',
        'open_sections' => 'array',
        'required_documents' => 'array',
        'whatsapp_enabled' => 'boolean',
        'email_enabled' => 'boolean',
        'phone_enabled' => 'boolean',
    ];

    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self([
                'school_id' => $schoolId,
                'public_form_enabled' => false,
                'open_sections' => ['nursery', 'primary', 'secondary'],
                'required_documents' => self::DEFAULT_DOCUMENTS,
                'retention_days' => 365,
                'offer_validity_days' => 14,
            ]);
    }

    protected static function booted(): void
    {
        // Before admissions had a status, switching the online form on was how
        // a school opened admissions. Anything still writing it that way (and
        // the migration's backfill) means "open".
        static::saving(function (self $setting) {
            if ($setting->public_form_enabled && $setting->admissions_status === null) {
                $setting->admissions_status = 'open';
            }
        });
    }

    public function isOpen(): bool
    {
        return $this->admissions_status === 'open';
    }

    /** The online form takes applications only while admissions are open. */
    public function acceptsOnline(): bool
    {
        return $this->isOpen() && $this->public_form_enabled;
    }

    /**
     * What a school's public page shows about admissions: open or closed,
     * the session, the school's own words, and only the contact methods it
     * turned on. null when the school has never set its admissions status.
     *
     * @return array<string, mixed>|null
     */
    public function publicSummary(): ?array
    {
        if (! in_array($this->admissions_status, ['open', 'closed'], true)) {
            return null;
        }

        if (! $this->isOpen()) {
            return [
                'status' => 'closed',
                'session' => $this->admission_session,
                'closed_message' => $this->closed_message,
            ];
        }

        return [
            'status' => 'open',
            'session' => $this->admission_session,
            'instructions' => $this->instructions,
            'methods' => [
                'online' => (bool) $this->public_form_enabled,
                'whatsapp' => $this->whatsapp_enabled && $this->whatsapp_number
                    ? ['number' => ltrim($this->whatsapp_number, '+'), 'message' => $this->whatsapp_message]
                    : null,
                'email' => $this->email_enabled && $this->admissions_email
                    ? ['address' => $this->admissions_email, 'subject' => $this->email_subject]
                    : null,
                'phone' => $this->phone_enabled && $this->admissions_phone ? $this->admissions_phone : null,
            ],
        ];
    }

    /** @return array<int, string> */
    public function documentsFor(string $section): array
    {
        return ($this->required_documents ?: self::DEFAULT_DOCUMENTS)[$section] ?? [];
    }
}
