<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TutorMessage extends Model
{
    use HasFactory;

    protected $table = 'tutor_messages';

    protected $fillable = [
        'conversation_id',
        'role',
        'body',
        'was_redirected',
        'topic',
    ];

    protected $casts = [
        'was_redirected' => 'boolean',
    ];

    public function conversation()
    {
        return $this->belongsTo(TutorConversation::class, 'conversation_id');
    }
}
