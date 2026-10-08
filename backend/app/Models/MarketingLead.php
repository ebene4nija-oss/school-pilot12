<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A school that asked to hear from SchoolPilot through the marketing site.
 *
 * Not `BelongsToTenant`: a lead is not a tenant yet, and only platform
 * operators ever read these rows.
 */
class MarketingLead extends Model
{
    public const STATUSES = [
        'new', 'contacted', 'qualified', 'demo_scheduled', 'demo_done',
        'onboarding', 'live', 'nurture', 'lost',
    ];

    public const ROLES = ['proprietor', 'principal', 'admin', 'bursar', 'ict', 'teacher', 'other'];

    /** Bands map onto pricing bands, so a visitor never types a raw number. */
    public const BANDS = ['1-50', '51-200', '201-500', '501-1000', '1001-3000', '3000+'];

    public const SECTIONS = ['nursery', 'primary', 'jss', 'ss'];

    /** A lead at or above this score pings sales at once (Landing PRD §12.3). */
    public const HOT_SCORE = 8;

    protected $fillable = [
        'intent', 'full_name', 'phone', 'email', 'school_name', 'role',
        'student_band', 'sections', 'preferred_contact', 'current_method',
        'message', 'preferred_subdomain', 'migrate_what',
        'ref_code', 'landing_page', 'referrer',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'pages', 'status', 'score', 'owner_id', 'next_action_at', 'notes',
        'lost_reason', 'consent_at', 'last_contact_at', 'partner_id',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    protected $casts = [
        'full_name' => 'encrypted',
        'phone' => 'encrypted',
        'email' => 'encrypted',
        'message' => 'encrypted',
        'sections' => 'array',
        'pages' => 'array',
        'score' => 'integer',
        'next_action_at' => 'datetime',
        'consent_at' => 'datetime',
        'last_contact_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isHot(): bool
    {
        return $this->score >= self::HOT_SCORE;
    }
}
