<?php

namespace App\Support;

/**
 * Every AI model SchoolPilot offers, in one place: what the console lists,
 * what the presets pick, and what the spend ledger charges.
 *
 * Prices are USD per million tokens from each provider's own pricing page,
 * checked 2026-10-08:
 *  - Anthropic: the Claude API model reference
 *  - OpenAI: developers.openai.com/api/docs/pricing (standard tier)
 *  - Google: ai.google.dev/gemini-api/docs/pricing (paid tier, prompts ≤200k)
 *
 * `cached` is the price of a cached prompt token where the provider lists
 * one; otherwise the ledger charges a tenth of `input`. `thinks` marks models
 * that reason before answering and count it as output — those get extra
 * room in the token limit, or a two-sentence remark comes back empty.
 * A price change with a known date uses `*_after_intro` and `intro_ends`.
 *
 * A model id typed into the console that is not here still works; the
 * ledger then charges it at the highest rate in this list, so the daily cap
 * holds until it is added.
 */
final class AiModels
{
    public const TIERS = ['fast' => 'Fast and cheap', 'balanced' => 'Balanced', 'best' => 'Best quality'];

    /** @var array<string, list<array<string, mixed>>> */
    public const MODELS = [
        'anthropic' => [
            ['id' => 'claude-haiku-4-5', 'label' => 'Claude Haiku 4.5', 'tier' => 'fast', 'input' => 1.00, 'output' => 5.00, 'cached' => 0.10, 'thinks' => false],
            ['id' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5', 'tier' => 'balanced', 'input' => 2.00, 'output' => 10.00, 'cached' => 0.20, 'thinks' => true],
            [
                'id' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5', 'tier' => 'balanced', 'thinks' => true,
                'input' => 2.00, 'output' => 10.00,
                // Introductory pricing ended 2026-08-31.
                'input_after_intro' => 3.00, 'output_after_intro' => 15.00, 'intro_ends' => '2026-08-31',
            ],
            ['id' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5', 'tier' => 'best', 'input' => 4.00, 'output' => 20.00, 'cached' => 0.20, 'thinks' => true],
            ['id' => 'claude-opus-5', 'label' => 'Claude Opus 5', 'tier' => 'best', 'input' => 5.00, 'output' => 25.00, 'thinks' => true],
            ['id' => 'claude-fable-5-1', 'label' => 'Claude Fable 5.1', 'tier' => 'best', 'input' => 10.00, 'output' => 50.00, 'cached' => 0.25, 'thinks' => true, 'note' => 'Most capable and most expensive.'],
        ],
        'openai' => [
            ['id' => 'gpt-6-luna', 'label' => 'GPT-6 Luna', 'tier' => 'fast', 'input' => 0.10, 'output' => 0.50, 'cached' => 0.01, 'thinks' => true],
            ['id' => 'gpt-5-mini', 'label' => 'GPT-5 mini', 'tier' => 'fast', 'input' => 0.25, 'output' => 2.00, 'cached' => 0.025, 'thinks' => true],
            ['id' => 'gpt-5.4-mini', 'label' => 'GPT-5.4 mini', 'tier' => 'fast', 'input' => 0.75, 'output' => 4.50, 'cached' => 0.075, 'thinks' => true],
            ['id' => 'gpt-6.1-sol', 'label' => 'GPT-6.1 Sol', 'tier' => 'balanced', 'input' => 2.00, 'output' => 10.00, 'cached' => 0.10, 'thinks' => true],
            ['id' => 'gpt-5.6-terra', 'label' => 'GPT-5.6 Terra', 'tier' => 'balanced', 'input' => 2.00, 'output' => 12.00, 'cached' => 0.20, 'thinks' => true],
            ['id' => 'gpt-5', 'label' => 'GPT-5', 'tier' => 'balanced', 'input' => 1.25, 'output' => 10.00, 'cached' => 0.125, 'thinks' => true],
            ['id' => 'gpt-6-astra', 'label' => 'GPT-6 Astra', 'tier' => 'best', 'input' => 10.00, 'output' => 50.00, 'cached' => 1.00, 'thinks' => true, 'note' => 'Most capable and most expensive.'],
        ],
        'gemini' => [
            ['id' => 'gemini-3.1-flash-lite', 'label' => 'Gemini 3.1 Flash-Lite', 'tier' => 'fast', 'input' => 0.25, 'output' => 1.50, 'cached' => 0.025, 'thinks' => true],
            ['id' => 'gemini-2.5-flash', 'label' => 'Gemini 2.5 Flash', 'tier' => 'fast', 'input' => 0.30, 'output' => 2.50, 'thinks' => true],
            [
                'id' => 'gemini-3.8-flash', 'label' => 'Gemini 3.8 Flash', 'tier' => 'balanced', 'thinks' => true,
                'input' => 0.75, 'output' => 3.75, 'cached' => 0.075,
                // Google's listed price rises on 2027-01-01.
                'input_after_intro' => 1.50, 'output_after_intro' => 7.50, 'cached_after_intro' => 0.15, 'intro_ends' => '2026-12-31',
            ],
            ['id' => 'gemini-2.5-pro', 'label' => 'Gemini 2.5 Pro', 'tier' => 'balanced', 'input' => 1.25, 'output' => 10.00, 'thinks' => true],
            ['id' => 'gemini-3.1-pro-preview', 'label' => 'Gemini 3.1 Pro (preview)', 'tier' => 'best', 'input' => 2.00, 'output' => 12.00, 'cached' => 0.20, 'thinks' => true, 'note' => 'Preview: Google may change or withdraw it.'],
        ],
    ];

    /**
     * One click sets both models for a provider. `comment` is short output
     * (remarks, the tutor); `document` is lesson plans, worksheets, exams.
     *
     * @var array<string, array<string, array{comment: string, document: string}>>
     */
    public const PRESETS = [
        'anthropic' => [
            'fast' => ['comment' => 'claude-haiku-4-5', 'document' => 'claude-haiku-4-5'],
            'balanced' => ['comment' => 'claude-haiku-4-5', 'document' => 'claude-sonnet-5-5'],
            'best' => ['comment' => 'claude-sonnet-5-5', 'document' => 'claude-opus-5-5'],
        ],
        'openai' => [
            'fast' => ['comment' => 'gpt-5-mini', 'document' => 'gpt-5-mini'],
            'balanced' => ['comment' => 'gpt-5-mini', 'document' => 'gpt-6.1-sol'],
            'best' => ['comment' => 'gpt-6.1-sol', 'document' => 'gpt-6-astra'],
        ],
        'gemini' => [
            'fast' => ['comment' => 'gemini-3.1-flash-lite', 'document' => 'gemini-3.1-flash-lite'],
            'balanced' => ['comment' => 'gemini-3.1-flash-lite', 'document' => 'gemini-3.8-flash'],
            'best' => ['comment' => 'gemini-3.8-flash', 'document' => 'gemini-3.1-pro-preview'],
        ],
    ];

    /**
     * How a model id must start for each provider, so a model pasted into
     * the wrong provider's box is refused instead of failing on first use.
     */
    public const ID_PATTERNS = [
        'anthropic' => '/^claude-[a-z0-9][a-z0-9.\-]{1,78}$/',
        'openai' => '/^(gpt-|o[0-9]|chatgpt-)[a-z0-9.\-]{0,78}$/',
        'gemini' => '/^gemini-[a-z0-9][a-z0-9.\-]{1,78}$/',
    ];

    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        foreach (self::MODELS as $provider => $models) {
            foreach ($models as $model) {
                if ($model['id'] === $id) {
                    return $model + ['provider' => $provider];
                }
            }
        }

        return null;
    }

    /** Whether an id is acceptable for this provider: listed, or well-formed. */
    public static function validFor(string $provider, string $id): bool
    {
        $known = self::find($id);

        if ($known !== null) {
            return $known['provider'] === $provider;
        }

        return isset(self::ID_PATTERNS[$provider]) && preg_match(self::ID_PATTERNS[$provider], $id) === 1;
    }

    /** Unknown models are assumed to think: extra headroom is cheap, an empty remark is not. */
    public static function thinks(string $id): bool
    {
        return (bool) (self::find($id)['thinks'] ?? true);
    }

    /**
     * The ledger's price table, keyed by model id.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pricing(): array
    {
        $table = [];

        foreach (self::MODELS as $models) {
            foreach ($models as $model) {
                $table[$model['id']] = array_intersect_key($model, array_flip([
                    'input', 'output', 'cached', 'input_after_intro', 'output_after_intro', 'cached_after_intro', 'intro_ends',
                ]));
            }
        }

        return $table;
    }
}
