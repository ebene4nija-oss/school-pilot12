<?php

namespace App\Services\Ai;

use App\Models\School;
use App\Services\Ai\Providers\AiProvider;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\AiSpendLedger;
use RuntimeException;

/**
 * Every AI request in the product goes through here, whichever provider
 * answers it: Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini).
 *
 * Which provider, in order:
 *  1. a school's own key (BYO, `ai_api_settings`), with its provider;
 *  2. the provider the school chose on its AI features page, if SchoolPilot
 *     has a key for it;
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

        return [
            'provider' => $provider,
            'api_key' => config("services.{$provider}.api_key"),
            'model' => (string) config("services.{$provider}.{$purpose}_model"),
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

        $reply = $this->driver($config['provider'])->send(
            (string) $config['api_key'],
            $config['model'],
            $system,
            $messages,
            $maxTokens,
            $config['temperature'],
        );

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
