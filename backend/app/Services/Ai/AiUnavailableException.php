<?php

namespace App\Services\Ai;

use RuntimeException;

/** No key is configured for the provider a school would use. */
class AiUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct('No '.AiGateway::label($provider).' API key is configured, so AI generation is unavailable.');
    }

    /** "Anthropic", "OpenAI", "Google" — for messages shown to people. */
    public function vendor(): string
    {
        return ['anthropic' => 'Anthropic', 'openai' => 'OpenAI', 'gemini' => 'Google Gemini'][$this->provider] ?? $this->provider;
    }
}
