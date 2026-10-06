<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiReply;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** ChatGPT models, through OpenAI's Chat Completions API. */
class OpenAiProvider implements AiProvider
{
    public function send(string $apiKey, string $model, ?string $system, array $messages, int $maxTokens, ?float $temperature): AiReply
    {
        $chat = $system !== null ? [['role' => 'system', 'content' => $system]] : [];

        foreach ($messages as $m) {
            $chat[] = ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $m['content']];
        }

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->connectTimeout(10)
            ->retry(2, 500, throw: false)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => $chat,
                /*
                 * GPT-5 models reason before answering, and that reasoning is
                 * counted in this limit. A two-sentence remark given the 150
                 * tokens Claude needs would come back empty, so the limit has
                 * headroom; the daily cap still bounds what is spent. No
                 * temperature: these models refuse any value but the default.
                 */
                'max_completion_tokens' => max($maxTokens * 4, 4000),
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI API call failed: '.$response->body());
        }

        $choice = (array) $response->json('choices.0', []);
        $finish = $choice['finish_reason'] ?? null;
        $refused = ! empty($choice['message']['refusal']) || $finish === 'content_filter';
        $usage = (array) $response->json('usage', []);
        $cached = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);

        return new AiReply(
            text: (string) ($choice['message']['content'] ?? ''),
            stop: $refused ? 'refusal' : ($finish === 'length' ? 'max_tokens' : 'end'),
            usage: [
                // Cached prompt tokens are included in prompt_tokens here; the
                // ledger bills them separately at the cache-read rate.
                'input_tokens' => max(0, (int) ($usage['prompt_tokens'] ?? 0) - $cached),
                'cache_read_input_tokens' => $cached,
                'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            ],
            provider: 'openai',
            model: $model,
        );
    }
}
