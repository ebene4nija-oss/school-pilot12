<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TutorConversation extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'tutor_conversations';

    protected $fillable = [
        'school_id',
        'student_id',
        'subject_id',
        'homework_id',
        'title',
        'last_message_at',
        'message_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'message_count' => 'integer',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function homework()
    {
        return $this->belongsTo(Homework::class);
    }

    public function messages()
    {
        return $this->hasMany(TutorMessage::class, 'conversation_id')->orderBy('id');
    }
}
