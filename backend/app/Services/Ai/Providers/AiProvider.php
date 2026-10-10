<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiReply;

interface AiProvider
{
    /**
     * Send one request and normalise the reply.
     *
     * `$messages` alternate `user` / `assistant`, ending with `user`. Throws
     * a RuntimeException when the call fails; the caller decides how to word
     * that for the person waiting.
     *
     * A message's `content` is a string, or a list of parts for a request
     * that carries pictures: `['type' => 'text', 'text' => …]` and
     * `['type' => 'image', 'mime' => 'image/png', 'data' => base64]`. Each
     * provider writes those in its own format.
     *
     * @param  array<int, array{role: string, content: string|array<int, array<string, string>>}>  $messages
     */
    public function send(string $apiKey, string $model, ?string $system, array $messages, int $maxTokens, ?float $temperature): AiReply;
}
