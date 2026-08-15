<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A shared stimulus with several sub-questions hanging off it: a comprehension
 * passage, a data table, a labelled diagram.
 *
 * A group is presentation and context, never marks. `total_marks` sums the
 * sub-questions exactly as it did before groups existed, so putting six
 * questions behind a passage cannot change what a paper is worth.
 */
class CbtQuestionGroup extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'cbt_question_groups';

    protected $fillable = [
        'school_id',
        'subject_id',
        'title',
        'stimulus',
        'content_format',
        'media',
        'instructions',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'media' => 'array',
        'metadata' => 'array',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function questions()
    {
        return $this->hasMany(QuestionBankItem::class, 'group_id')
            ->orderByRaw('group_sequence is null, group_sequence')
            ->orderBy('id');
    }

    /** Media asset ids referenced by the stimulus. */
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
     * The candidate-facing shape. Nothing here is marking-scheme data — a
     * stimulus is meant to be read by the candidate — but it is projected
     * explicitly rather than serialised wholesale so `metadata` (reserved,
     * and therefore anything a future author puts in it) never leaks into a
     * paper or a bundle.
     */
    public function toCandidatePayload(array $hydratedMedia = []): array
    {
        return [
            'group_id' => $this->id,
            'title' => $this->title,
            'stimulus' => $this->stimulus,
            'content_format' => $this->content_format ?? 'plain',
            'instructions' => $this->instructions,
            'media' => $hydratedMedia,
        ];
    }
}
