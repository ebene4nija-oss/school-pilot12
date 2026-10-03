<?php

namespace App\Models;

use App\Support\Sections;
use Illuminate\Database\Eloquent\Model;

/** A school's section-level choices: heads mode, approval chains, delegates. */
class SchoolSectionSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'secondary_heads_mode',
        'approval_chains',
        'scheme_approval_delegates',
        'result_workflow_enabled',
    ];

    protected $casts = [
        'approval_chains' => 'array',
        'scheme_approval_delegates' => 'array',
        'result_workflow_enabled' => 'boolean',
    ];

    /** The school's settings, unsaved defaults when it has never set any. */
    public static function for(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first()
            ?? new self([
                'school_id' => $schoolId,
                'secondary_heads_mode' => 'single_principal',
                'approval_chains' => null,
                'scheme_approval_delegates' => [],
                'result_workflow_enabled' => false,
            ]);
    }

    /** Each section's chain, defaults filled in. */
    public function chains(): array
    {
        $stored = (array) ($this->approval_chains ?? []);

        return collect(Sections::DEFAULT_CHAINS)
            ->mapWithKeys(fn ($default, $section) => [$section => $stored[$section] ?? $default])
            ->all();
    }

    public function separateSecondaryHeads(): bool
    {
        return $this->secondary_heads_mode === 'separate_heads';
    }
}
