<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A school has spent its daily AI budget.
 *
 * Deliberately not a silent degrade. The alternative — returning canned text
 * once the budget runs out — is the same failure the Anthropic stub path was
 * corrected for: a teacher cannot tell a budget-exhausted remark from a real
 * one, and it would reach a report card.
 */
class AiBudgetExceededException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(
        public readonly int $schoolId,
        public readonly int $spentKobo,
        public readonly int $capKobo,
    ) {
        parent::__construct(sprintf(
            'This school has reached its daily AI budget (₦%s of ₦%s). AI features resume tomorrow, or an administrator can raise the cap.',
            number_format($spentKobo / 100, 2),
            number_format($capKobo / 100, 2),
        ));
    }

    /** Too Many Requests — the work is legitimate, the quota is spent. */
    public function getStatusCode(): int
    {
        return 429;
    }

    /**
     * Budgets reset at midnight, so tell the client when to come back rather
     * than leaving a mobile client to retry into a wall all evening.
     *
     * @return array<string,string>
     */
    public function getHeaders(): array
    {
        return ['Retry-After' => (string) max(1, now()->diffInSeconds(now()->endOfDay()))];
    }
}
