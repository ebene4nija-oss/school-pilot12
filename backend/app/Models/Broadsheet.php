<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One class's results for one term, moving through the school's chain.
 *
 * The broadsheet is a workflow and a record, never a second copy of the
 * scores: figures come from ScoreEntry through ResultCalculator, and what was
 * approved is frozen in BroadsheetVersion.
 */
class Broadsheet extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'in_review', 'returned', 'approved', 'published', 'rejected'];

    protected $fillable = [
        'school_id',
        'term_id',
        'class_id',
        'reference',
        'level_group',
        'status',
        'steps',
        'current_step',
        'current_version',
        'report_cards_stale',
        'published_at',
    ];

    protected $casts = [
        'steps' => 'array',
        'current_step' => 'integer',
        'current_version' => 'integer',
        'report_cards_stale' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function submissions()
    {
        return $this->hasMany(BroadsheetSubjectSubmission::class);
    }

    public function events()
    {
        return $this->hasMany(BroadsheetEvent::class)->orderBy('id');
    }

    public function versions()
    {
        return $this->hasMany(BroadsheetVersion::class)->orderByDesc('version');
    }

    public function corrections()
    {
        return $this->hasMany(BroadsheetCorrection::class);
    }

    /** Approved or published: ordinary edits are closed. */
    public function isLocked(): bool
    {
        return in_array($this->status, ['approved', 'published'], true);
    }

    public function currentStepRole(): ?string
    {
        return $this->steps[$this->current_step]['role'] ?? null;
    }

    public function latestVersion(): ?BroadsheetVersion
    {
        return $this->versions()->whereNull('superseded_at')->first();
    }
}
