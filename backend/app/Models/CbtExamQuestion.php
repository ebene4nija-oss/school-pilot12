<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CbtExamQuestion extends Model
{
    use HasFactory;

    protected $table = 'cbt_exam_questions';

    protected $fillable = [
        'exam_id',
        'question_id',
        'order_index',
        'marks',
        'negative_marks',
        'section',
    ];

    protected $casts = [
        'order_index' => 'integer',
        'marks' => 'decimal:2',
        'negative_marks' => 'decimal:2',
    ];

    public function exam()
    {
        return $this->belongsTo(CbtExam::class, 'exam_id');
    }

    public function question()
    {
        return $this->belongsTo(QuestionBankItem::class, 'question_id');
    }

    /** Exam-level mark override, falling back to the bank item's own value. */
    public function effectiveMarks(QuestionBankItem $question): float
    {
        return (float) ($this->marks ?? $question->marks ?? 1.0);
    }

    public function effectiveNegativeMarks(QuestionBankItem $question): float
    {
        return (float) ($this->negative_marks ?? $question->negative_marks ?? 0.0);
    }
}
