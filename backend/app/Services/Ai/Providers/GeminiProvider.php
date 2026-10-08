<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiReply;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Google Gemini, through the Gemini API's generateContent. */
class GeminiProvider implements AiProvider
{
    private const BLOCKED = ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY'];

    public function send(string $apiKey, string $model, ?string $system, array $messages, int $maxTokens, ?float $temperature): AiReply
    {
        $contents = array_map(fn (array $m) => [
            'role' => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $messages);

        $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
            ->timeout(60)
            ->connectTimeout(10)
            ->retry(2, 500, throw: false)
            ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', array_filter([
                'systemInstruction' => $system !== null ? ['parts' => [['text' => $system]]] : null,
                'contents' => $contents,
                'generationConfig' => array_filter([
                    // Includes thinking; AiGateway sizes it for thinking models.
                    'maxOutputTokens' => $maxTokens,
                    'temperature' => $temperature,
                ], fn ($v) => $v !== null),
            ], fn ($v) => $v !== null));

        if ($response->failed()) {
            throw new RuntimeException('Gemini API call failed: '.$response->body());
        }

        $candidate = (array) $response->json('candidates.0', []);
        $finish = $candidate['finishReason'] ?? null;
        $refused = $response->json('promptFeedback.blockReason') !== null || in_array($finish, self::BLOCKED, true);

        $text = collect((array) ($candidate['content']['parts'] ?? []))
            ->reject(fn ($p) => ! empty($p['thought']))
            ->pluck('text')
            ->implode('');

        $usage = (array) $response->json('usageMetadata', []);
        $cached = (int) ($usage['cachedContentTokenCount'] ?? 0);

        return new AiReply(
            text: $text,
            stop: $refused ? 'refusal' : ($finish === 'MAX_TOKENS' ? 'max_tokens' : 'end'),
            usage: [
                'input_tokens' => max(0, (int) ($usage['promptTokenCount'] ?? 0) - $cached),
                'cache_read_input_tokens' => $cached,
                // Thinking is billed as output.
                'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            ],
            provider: 'gemini',
            model: $model,
        );
    }
}
