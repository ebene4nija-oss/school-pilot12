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

    protected $fillable = ['school_id', 'public_form_enabled', 'open_sections', 'required_documents', 'retention_days', 'offer_validity_days', 'offer_letter_note'];

    protected $casts = ['public_form_enabled' => 'boolean', 'open_sections' => 'array', 'required_documents' => 'array'];

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

    /** @return array<int, string> */
    public function documentsFor(string $section): array
    {
        return ($this->required_documents ?: self::DEFAULT_DOCUMENTS)[$section] ?? [];
    }
}
