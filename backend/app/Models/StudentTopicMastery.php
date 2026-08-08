<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentTopicMastery extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'student_topic_mastery';

    protected $fillable = [
        'school_id',
        'student_id',
        'subject_id',
        'topic',
        'questions_attempted',
        'questions_correct',
        'tutor_sessions',
        'mastery_percentage',
        'last_practised_at',
    ];

    protected $casts = [
        'questions_attempted' => 'integer',
        'questions_correct' => 'integer',
        'tutor_sessions' => 'integer',
        'mastery_percentage' => 'decimal:2',
        'last_practised_at' => 'datetime',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Fold a graded question result into the running mastery figure.
     *
     * Counters rather than a stored average: a student who answers three more
     * questions on photosynthesis should move the number by the right amount
     * without us re-reading every attempt they have ever sat.
     */
    public static function recordAnswer(
        int $schoolId,
        int $studentId,
        ?int $subjectId,
        ?string $topic,
        bool $correct
    ): void {
        if (! $topic) {
            return;
        }

        $row = static::firstOrNew([
            'student_id' => $studentId,
            'subject_id' => $subjectId,
            'topic' => $topic,
        ]);

        $row->school_id = $schoolId;
        $row->questions_attempted = ($row->questions_attempted ?? 0) + 1;
        $row->questions_correct = ($row->questions_correct ?? 0) + ($correct ? 1 : 0);
        $row->mastery_percentage = round(($row->questions_correct / $row->questions_attempted) * 100, 2);
        $row->last_practised_at = now();
        $row->save();
    }

    public static function recordTutorSession(int $schoolId, int $studentId, ?int $subjectId, ?string $topic): void
    {
        if (! $topic) {
            return;
        }

        $row = static::firstOrNew([
            'student_id' => $studentId,
            'subject_id' => $subjectId,
            'topic' => $topic,
        ]);

        $row->school_id = $schoolId;
        $row->tutor_sessions = ($row->tutor_sessions ?? 0) + 1;
        $row->last_practised_at = now();
        $row->save();
    }
}
