<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiReply;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Claude, through the Messages API. */
class AnthropicProvider implements AiProvider
{
    public function send(string $apiKey, string $model, ?string $system, array $messages, int $maxTokens, ?float $temperature): AiReply
    {
        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            // Without a timeout a stalled call pins a PHP-FPM worker
            // indefinitely, which matters on the connectivity these schools have.
            ->timeout(60)
            ->connectTimeout(10)
            ->retry(2, 500, throw: false)
            ->post('https://api.anthropic.com/v1/messages', array_filter([
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'temperature' => $temperature,
                'messages' => $messages,
            ], fn ($v) => $v !== null));

        if ($response->failed()) {
            throw new RuntimeException('Claude API call failed: '.$response->body());
        }

        $text = collect((array) $response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        return new AiReply(
            text: $text,
            stop: match ($response->json('stop_reason')) {
                'max_tokens' => 'max_tokens',
                'refusal' => 'refusal',
                default => 'end',
            },
            usage: array_map('intval', array_intersect_key((array) $response->json('usage', []), array_flip([
                'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens',
            ]))),
            provider: 'anthropic',
            model: $model,
        );
    }
}
