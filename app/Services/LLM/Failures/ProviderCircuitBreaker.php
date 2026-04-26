<?php

namespace App\Services\LLM\Failures;

/**
 * Previously tracked consecutive failures in cache and blocked a provider.
 * This is a no-op: every request is attempted; use HTTP retry/timeouts and logging instead.
 */
class ProviderCircuitBreaker
{
    public function recordFailure(string $provider): void {}

    public function clearFailures(string $provider): void {}

    public function isBlocked(string $provider): bool
    {
        return false;
    }
}
