<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The anonymous trail behind one WhatsApp click on the marketing site: a ref
 * code and where the visitor came from. Never a name, phone or email.
 */
class MarketingTouch extends Model
{
    protected $fillable = [
        'ref_code', 'cta', 'landing_page', 'referrer',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'pages',
    ];

    protected $casts = [
        'pages' => 'array',
    ];
}
