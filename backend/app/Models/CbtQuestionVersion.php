<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A question as it stood at one version. Never edited. */
class CbtQuestionVersion extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /** Fields whose change can alter who is right. */
    public const SCORING_FIELDS = ['options', 'correct_answer', 'answer_schema', 'marks', 'negative_marks', 'question_type'];

    /** Fields captured in a version. */
    public const FIELDS = ['question', 'options', 'correct_answer', 'answer_schema', 'explanation', 'marks', 'negative_marks', 'media', 'question_type', 'topic', 'difficulty'];

    protected $fillable = ['school_id', 'question_id', 'version', 'payload', 'change_summary', 'affects_scoring', 'created_by'];

    protected $casts = ['payload' => 'array', 'affects_scoring' => 'boolean'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Question versions are never edited.'));
        static::deleting(fn () => throw new LogicException('Question versions are never deleted.'));
    }
}
