<?php

namespace App\Services\Ai;

use App\Models\School;
use App\Services\Ai\Providers\AiProvider;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\AiSpendLedger;
use App\Support\AiModels;
use RuntimeException;

/**
 * Every AI request in the product goes through here, whichever provider
 * answers it: Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini).
 *
 * Which provider, in order:
 *  1. a school's own key (BYO, `ai_api_settings`), with its provider;
 *  2. the provider the school chose on its AI features page, if SchoolPilot
 *     has a key for it, at the quality tier the school chose (if any);
 *  3. SchoolPilot's default (`services.ai.provider`).
 *
 * Whatever is chosen, the same rules hold: no key means a loud failure (or,
 * in stub mode, the caller's labelled placeholder), the school's daily cap is
 * checked before the call, the reported usage is metered after it, and a
 * refusal or an empty reply is never handed on as if it were an answer.
 */
class AiGateway
{
    public const PROVIDERS = [
        'anthropic' => 'Anthropic (Claude)',
        'openai' => 'OpenAI (ChatGPT)',
        'gemini' => 'Google (Gemini)',
    ];

    /** Providers SchoolPilot has a key for. @return list<string> */
    public static function configuredProviders(): array
    {
        return array_values(array_filter(
            array_keys(self::PROVIDERS),
            fn (string $p) => self::filled(config("services.{$p}.api_key")),
        ));
    }

    public static function defaultProvider(): string
    {
        $p = (string) config('services.ai.provider', 'anthropic');

        return array_key_exists($p, self::PROVIDERS) ? $p : 'anthropic';
    }

    public static function label(string $provider): string
    {
        return self::PROVIDERS[$provider] ?? $provider;
    }

    /**
     * Provider, key and model for a school and purpose (`comment` for short
     * output, `document` for structured documents).
     *
     * @return array{provider: string, api_key: ?string, model: string, own_key: bool, temperature: ?float}
     */
    public function resolve(?int $schoolId, string $purpose = 'comment'): array
    {
        $school = $schoolId ? School::find($schoolId) : null;
        $own = $school ? (array) ($school->ai_api_settings ?? []) : [];

        if (self::filled($own['api_key'] ?? null)) {
            $provider = array_key_exists($own['provider'] ?? '', self::PROVIDERS) ? $own['provider'] : 'anthropic';

            return [
                'provider' => $provider,
                'api_key' => (string) $own['api_key'],
                'model' => (string) (($own['model'] ?? null) ?: config("services.{$provider}.{$purpose}_model")),
                'own_key' => true,
                'temperature' => isset($own['temperature']) ? (float) $own['temperature'] : null,
            ];
        }

        $chosen = $school?->ai_provider;
        $provider = $chosen && in_array($chosen, self::configuredProviders(), true) ? $chosen : self::defaultProvider();

        // A school may pick a quality tier; otherwise SchoolPilot's models
        // for that provider apply. The daily cap holds either way.
        $tier = $school?->ai_model_tier;
        $preset = $tier ? (AiModels::PRESETS[$provider][$tier] ?? null) : null;

        return [
            'provider' => $provider,
            'api_key' => config("services.{$provider}.api_key"),
            'model' => (string) ($preset[$purpose] ?? config("services.{$provider}.{$purpose}_model")),
            'own_key' => false,
            'temperature' => null,
        ];
    }

    /** Whether the caller should serve its labelled placeholder instead. */
    public function shouldStub(?int $schoolId): bool
    {
        return (bool) config('services.anthropic.stub') && ! self::filled($this->resolve($schoolId)['api_key']);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function complete(?int $schoolId, string $purpose, ?string $system, array $messages, int $maxTokens): AiReply
    {
        $config = $this->resolve($schoolId, $purpose);

        if (! self::filled($config['api_key'])) {
            // Loud failure. The caller queues or surfaces this; it must never
            // degrade into invented content that looks like a real remark.
            throw new AiUnavailableException($config['provider']);
        }

        // Checked before the call, not enforced mid-flight: a school can end
        // fractionally over its cap rather than have a half-written report-card
        // comment truncated. A school on its own key is capped too — the cap
        // stops a runaway loop, which costs them just as much.
        $ledger = app(AiSpendLedger::class);
        $ledger->assertWithinCap($schoolId);

        $reply = $this->send($config['provider'], (string) $config['api_key'], $config['model'], $system, $messages, $maxTokens, $config['temperature']);

        // Metered from the provider's own usage figures, before the reply is
        // judged: a refused or truncated call is still billed.
        $ledger->record($schoolId, $reply->model, $reply->usage);

        // A declined request comes back as a success with no usable text; say
        // so rather than hand an empty string on as if it were the answer.
        if ($reply->stop === 'refusal') {
            throw new RuntimeException('The AI declined this request. Rephrase it and try again.');
        }

        if (trim($reply->text) === '') {
            throw new RuntimeException($reply->stop === 'max_tokens'
                ? 'The AI reply was cut short. Ask for less at once and try again.'
                : 'The AI returned an empty reply. Please try again.');
        }

        return $reply;
    }

    /**
     * A tiny live call with SchoolPilot's own key for a provider, from the
     * console's Test button: proves the key, the model id and the network
     * path before any school relies on them. Not charged to any school.
     *
     * @return array{ok: bool, provider: string, model: string, reply?: string, error?: string, ms: int}
     */
    public function test(string $provider, ?string $model = null): array
    {
        $model = $model ?: (string) config("services.{$provider}.comment_model");
        $key = config("services.{$provider}.api_key");
        $started = microtime(true);
        $result = ['provider' => $provider, 'model' => $model];

        if (! self::filled($key)) {
            return $result + ['ok' => false, 'error' => 'No key is set for '.self::label($provider).'.', 'ms' => 0];
        }

        try {
            $reply = $this->send($provider, (string) $key, $model, null, [
                ['role' => 'user', 'content' => 'Reply with the single word: ready'],
            ], 20, null);
            $text = trim($reply->text);

            return $result + [
                'ok' => $text !== '' && $reply->stop !== 'refusal',
                'reply' => mb_substr($text, 0, 80),
                'error' => $text === '' ? 'The model answered with no text.' : null,
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (\Throwable $e) {
            return $result + [
                'ok' => false,
                // The provider's own message says what is wrong (bad key,
                // unknown model, no credit); keys are never echoed in it.
                'error' => mb_substr($e->getMessage(), 0, 400),
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function send(string $provider, string $key, string $model, ?string $system, array $messages, int $maxTokens, ?float $temperature): AiReply
    {
        $thinks = AiModels::thinks($model);

        return $this->driver($provider)->send(
            $key,
            $model,
            $system,
            $messages,
            // A model that reasons first counts that reasoning in the limit,
            // so a two-sentence remark given 150 tokens comes back empty. The
            // daily cap, not this limit, is what bounds spend.
            $thinks ? max($maxTokens * 4, 4000) : $maxTokens,
            // Reasoning models refuse sampling parameters.
            $thinks ? null : $temperature,
        );
    }

    protected function driver(string $provider): AiProvider
    {
        return match ($provider) {
            'openai' => app(OpenAiProvider::class),
            'gemini' => app(GeminiProvider::class),
            default => app(AnthropicProvider::class),
        };
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
