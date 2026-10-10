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
        'shuffle_within_group',
        'questions_per_attempt',
        'max_attempts',
        'negative_marking',
        'pass_mark',
        'show_results_immediately',
        'review_answers',
        'review_correct_answers',
        'results_released_at',
        'results_released_by',
        'allow_offline',
        'integrity_settings',
        'total_marks',
        'created_by',
        'approval_status',
        'approval_note',
        'approved_by',
        'approved_at',
        'exam_type',
    ];

    protected $casts = [
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
        'shuffle_within_group' => 'boolean',
        'negative_marking' => 'boolean',
        'show_results_immediately' => 'boolean',
        'review_answers' => 'boolean',
        'review_correct_answers' => 'boolean',
        'results_released_at' => 'datetime',
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

    /** Work out the paper's total marks from its questions, and store it. */
    public function recalculateTotalMarks(): float
    {
        $perQuestion = CbtExamQuestion::where('exam_id', $this->id)->with('question')->get()
            ->map(fn ($link) => $link->question ? $link->effectiveMarks($link->question) : 0.0)
            ->sort()
            ->values();

        // With a subset draw, the paper is worth the N highest-value questions
        // at most — quoting the full bank total would overstate it.
        $total = $this->questions_per_attempt
            ? $perQuestion->reverse()->take($this->questions_per_attempt)->sum()
            : $perQuestion->sum();

        $total = round((float) $total, 2);

        if ((float) $this->total_marks !== $total) {
            $this->forceFill(['total_marks' => $total])->save();
        }

        return $total;
    }

    /**
     * Whether candidates (and their parents) may see their score: shown on
     * submit, or released by the school afterwards. Staff always see it.
     */
    public function resultsVisible(): bool
    {
        return (bool) $this->show_results_immediately || $this->results_released_at !== null;
    }

    /** Attempt statuses that use up one of a candidate's attempts. */
    public const SPENT_ATTEMPT_STATUSES = ['submitted', 'graded', 'expired'];

    /**
     * The exams in [$exams] this student can still sit: those where they have
     * not used every attempt allowed. A paper already taken stays out of
     * "upcoming tests" rather than inviting the student to a test they cannot
     * start.
     *
     * @param  \Illuminate\Support\Collection<int, self>  $exams
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function stillOpenTo(\Illuminate\Support\Collection $exams, int $studentId): \Illuminate\Support\Collection
    {
        if ($exams->isEmpty()) {
            return $exams;
        }

        $used = CbtAttempt::where('student_id', $studentId)
            ->whereIn('exam_id', $exams->pluck('id'))
            ->whereIn('status', self::SPENT_ATTEMPT_STATUSES)
            ->selectRaw('exam_id, COUNT(*) as n')
            ->groupBy('exam_id')
            ->pluck('n', 'exam_id');

        return $exams->filter(fn (self $e) => (int) ($used[$e->id] ?? 0) < max(1, (int) ($e->max_attempts ?? 1)))->values();
    }
}
