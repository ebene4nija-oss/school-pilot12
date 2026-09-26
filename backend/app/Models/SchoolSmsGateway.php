<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One school's own SMS account.
 *
 * The API key is ciphertext at rest and is never returned by any endpoint —
 * the school's settings screen sees that a key is set and its last four
 * characters, and nothing more.
 */
class SchoolSmsGateway extends Model
{
    use BelongsToTenant;

    public const PHONE_GATEWAY = 'phone_gateway';

    public const TERMII = 'termii';

    public const PROVIDERS = [self::PHONE_GATEWAY, self::TERMII];

    protected $fillable = [
        'school_id',
        'provider',
        'server_url',
        'api_key',
        'device',
        'sender_id',
        'is_active',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'is_active' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    protected $hidden = ['api_key'];

    public function keyHint(): string
    {
        $key = (string) $this->api_key;

        return mb_strlen($key) >= 8 ? '••••'.mb_substr($key, -4) : 'Set';
    }
}
