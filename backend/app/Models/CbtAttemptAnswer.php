<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CbtAttemptAnswer extends Model
{
    use HasFactory;

    protected $table = 'cbt_attempt_answers';

    protected $fillable = [
        'attempt_id',
        'question_id',
        'response',
        'client_timestamp',
        'client_sequence',
        'is_correct',
        'awarded_marks',
        'graded_by',
        'feedback',
        'flagged_for_review',
        'time_spent_seconds',
    ];

    protected $casts = [
        'response' => 'array',
        'client_timestamp' => 'datetime',
        'client_sequence' => 'integer',
        'is_correct' => 'boolean',
        'awarded_marks' => 'decimal:2',
        'flagged_for_review' => 'boolean',
        'time_spent_seconds' => 'integer',
    ];

    public function attempt()
    {
        return $this->belongsTo(CbtAttempt::class, 'attempt_id');
    }

    public function question()
    {
        return $this->belongsTo(QuestionBankItem::class, 'question_id');
    }
}
