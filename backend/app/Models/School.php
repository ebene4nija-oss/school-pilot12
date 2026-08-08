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
        'logo_url',
        'status',
        'ai_enabled',
        'ai_feature_flags',
        'ai_api_settings',
        'result_checker_enabled',
        'result_pin_retail_price',
    ];

    protected $casts = [
        'ai_enabled' => 'boolean',
        'result_checker_enabled' => 'boolean',
        'result_pin_retail_price' => 'decimal:2',
        'ai_feature_flags' => 'array',
        'ai_api_settings' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'geofence_radius_meters' => 'integer',
    ];
}
