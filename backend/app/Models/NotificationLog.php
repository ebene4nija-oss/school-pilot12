<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    use BelongsToTenant;

    protected $table = 'notification_logs';

    protected $fillable = [
        'school_id',
        'user_id',
        'sent_by',
        'channel',
        'category',
        'recipient',
        'body',
        'status',
        'failure_reason',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
