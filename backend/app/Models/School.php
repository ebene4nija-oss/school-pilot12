<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class School extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'subdomain',
        'email',
        'phone',
        'address',
        'latitude',
        'longitude',
        'geofence_radius_meters',
        'staff_resume_time',
        'logo_url',
        'status',
        'ai_enabled',
        'ai_feature_flags',
        'ai_api_settings',
        'result_checker_enabled',
        'result_pin_retail_price',
        'is_platform',
        'email_enabled',
        'email_daily_limit',
        'absence_alerts_enabled',
        'absence_alerts_sms',
        'short_name',
        'motto',
        'about',
        'hero_image_url',
        'state',
        'lga',
        'website',
        'social_links',
        'school_type',
        'brand_color',
        'partner_id',
    ];

    /** Never serialised: it carries the school's AI key. */
    protected $hidden = ['ai_api_settings'];

    protected $casts = [
        'is_platform' => 'boolean',
        'trial_ends_on' => 'date',
        'absence_alerts_enabled' => 'boolean',
        'absence_alerts_sms' => 'boolean',
        'email_enabled' => 'boolean',
        'email_daily_limit' => 'integer',
        'ai_enabled' => 'boolean',
        'result_checker_enabled' => 'boolean',
        'result_pin_retail_price' => 'decimal:2',
        'ai_feature_flags' => 'array',
        // Holds a school's own AI provider key. Encrypted at rest like the
        // SMS and payment keys; it was the one secret stored as plain JSON.
        'ai_api_settings' => 'encrypted:array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'geofence_radius_meters' => 'integer',
        'social_links' => 'array',
        'public_profile_published' => 'boolean',
        'public_profile_published_at' => 'datetime',
        'hero_image_confirmed_at' => 'datetime',
    ];

    /**
     * Real schools — the ones with pupils in them.
     *
     * SchoolPilot's own row exists only so the operator has a subdomain to sign
     * in through. Counting it as a tenant reported "Schools 1" for a platform
     * that had not yet onboarded anybody.
     */
    public function scopeTenants($query)
    {
        return $query->where('is_platform', false);
    }

    /** SchoolPilot's own row, not a customer. */
    public function scopePlatform($query)
    {
        return $query->where('is_platform', true);
    }
}
