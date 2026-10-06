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
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function send(string $apiKey, string $model, ?string $system, array $messages, int $maxTokens, ?float $temperature): AiReply;
}
