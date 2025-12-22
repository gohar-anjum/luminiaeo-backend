<?php

namespace App\Services\LLM\Failures;

use Illuminate\Support\Facades\Cache;

class ProviderCircuitBreaker
{
    protected string $prefix = 'llm_provider_failures_';

    public function recordFailure(string $provider): void
    {
        $key = $this->prefix . $provider;

        $failures = Cache::get($key, 0) + 1;
        Cache::put($key, $failures, now()->addMinutes(15));
    }

    public function clearFailures(string $provider): void
    {
        Cache::forget($this->prefix . $provider);
    }

    public function isBlocked(string $provider): bool
    {
        return Cache::get($this->prefix . $provider, 0) >= 3;
    }
}
