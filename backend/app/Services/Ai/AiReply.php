<?php

namespace App\Services\Ai;

/**
 * One model reply, the same shape whichever provider wrote it.
 *
 * `stop` is normalised: `end` (finished), `max_tokens` (cut short) or
 * `refusal` (declined). `usage` uses the ledger's keys — input_tokens,
 * output_tokens, cache_read_input_tokens, cache_creation_input_tokens — so
 * AiSpendLedger meters every provider the same way.
 */
final class AiReply
{
    /**
     * @param  array<string, int>  $usage
     */
    public function __construct(
        public readonly string $text,
        public readonly string $stop,
        public readonly array $usage,
        public readonly string $provider,
        public readonly string $model,
    ) {
    }
}
