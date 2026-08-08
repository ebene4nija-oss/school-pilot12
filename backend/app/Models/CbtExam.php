<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CbtExam extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'cbt_exams';

    protected $fillable = [
        'school_id',
        'subject_id',
        'class_id',
        'term_id',
        'title',
        'instructions',
        'content_format',
        'exam_body',
        'duration_minutes',
        'opens_at',
        'closes_at',
        'status',
        'shuffle_questions',
        'shuffle_options',
        'questions_per_attempt',
        'max_attempts',
        'negative_marking',
        'pass_mark',
        'show_results_immediately',
        'allow_offline',
        'integrity_settings',
        'total_marks',
        'created_by',
    ];

    protected $casts = [
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
        'negative_marking' => 'boolean',
        'show_results_immediately' => 'boolean',
        'allow_offline' => 'boolean',
        'integrity_settings' => 'array',
        'duration_minutes' => 'integer',
        'questions_per_attempt' => 'integer',
        'max_attempts' => 'integer',
        'pass_mark' => 'decimal:2',
        'total_marks' => 'decimal:2',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function examQuestions()
    {
        return $this->hasMany(CbtExamQuestion::class, 'exam_id')->orderBy('order_index');
    }

    public function attempts()
    {
        return $this->hasMany(CbtAttempt::class, 'exam_id');
    }

    /**
     * Whether candidates may sit the paper right now. Published status alone
     * is not enough — the window matters, and the window is judged against the
     * server clock only.
     */
    public function isOpenAt(\DateTimeInterface $moment): bool
    {
        if ($this->status !== 'published') {
            return false;
        }

        if ($this->opens_at && $moment < $this->opens_at) {
            return false;
        }

        if ($this->closes_at && $moment > $this->closes_at) {
            return false;
        }

        return true;
    }

    /**
     * Why the paper is unavailable, phrased for a candidate staring at a
     * screen five minutes before a test.
     */
    public function unavailableReason(\DateTimeInterface $moment): ?string
    {
        if ($this->status === 'draft') {
            return 'This exam has not been published yet.';
        }

        if ($this->status === 'closed') {
            return 'This exam has been closed by the school.';
        }

        if ($this->opens_at && $moment < $this->opens_at) {
            return 'This exam opens at ' . $this->opens_at->toDayDateTimeString() . '.';
        }

        if ($this->closes_at && $moment > $this->closes_at) {
            return 'This exam closed at ' . $this->closes_at->toDayDateTimeString() . '.';
        }

        return null;
    }
}
