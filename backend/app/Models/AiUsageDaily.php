<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One school's AI spend for one day. Written only by AiSpendLedger.
 */
class AiUsageDaily extends Model
{
    protected $table = 'ai_usage_daily';

    protected $fillable = [
        'school_id',
        'usage_date',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'cost_kobo',
        'calls',
        'usd_to_ngn',
        'alerted_at',
    ];

    protected $casts = [
        'usage_date' => 'date',
        'alerted_at' => 'datetime',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cache_read_tokens' => 'integer',
        'cache_write_tokens' => 'integer',
        'cost_kobo' => 'integer',
        'calls' => 'integer',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
