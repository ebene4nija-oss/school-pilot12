<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionBankItem extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'question_bank';

    /** Every question type the grading engine understands. */
    public const TYPES = [
        'multiple_choice',   // one correct option
        'multiple_response', // several correct options, all-or-nothing or partial
        'true_false',
        'fill_blank',        // free text matched against accepted spellings
        'numeric',           // numeric value with a tolerance band
        'matching',          // left item -> right item pairs
        'ordering',          // arrange items in sequence
        'image_choice',      // options are images rather than text
        'diagram_label',     // drag labels onto zones of a diagram
        'hotspot',           // click the correct region of an image
        'theory',            // manually graded; may carry a photo of working
    ];

    /** Types a machine can grade without a teacher in the loop. */
    public const AUTO_GRADED_TYPES = [
        'multiple_choice', 'multiple_response', 'true_false', 'fill_blank',
        'numeric', 'matching', 'ordering', 'image_choice', 'diagram_label', 'hotspot',
    ];

    protected $fillable = [
        'school_id',
        'subject_id',
        'topic',
        'question',
        'options',
        'correct_answer',
        'difficulty',
        'question_type',
        'metadata',
        'negative_marks',
        'content_format',
        'media',
        'answer_schema',
        'explanation',
        'marks',
        'exam_body',
        'status',
        'created_by',
    ];

    protected $casts = [
        'options' => 'array',
        'metadata' => 'array',
        'media' => 'array',
        'answer_schema' => 'array',
        'negative_marks' => 'decimal:2',
        'marks' => 'decimal:2',
        'times_answered' => 'integer',
        'times_correct' => 'integer',
        'discrimination_index' => 'float',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function isAutoGradable(): bool
    {
        return in_array($this->question_type, self::AUTO_GRADED_TYPES, true);
    }

    /** Media asset ids referenced anywhere on this question. */
    public function mediaAssetIds(): array
    {
        return collect($this->media ?? [])
            ->pluck('asset_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Proportion of candidates who got this right — the classical difficulty
     * index (p-value). Low p on a question the class was taught usually means
     * the question is broken, not that the class is.
     */
    public function difficultyIndex(): ?float
    {
        if (!$this->times_answered) {
            return null;
        }

        return round($this->times_correct / $this->times_answered, 4);
    }
}
