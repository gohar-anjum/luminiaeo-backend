<?php

namespace App\Services\LLM;

use App\Services\LLM\Drivers\OpenAiDriver;
use App\Services\LLM\Drivers\GeminiDriver;
use App\Services\LLM\Failures\ProviderCircuitBreaker;

class ProviderSelector
{
    protected array $providers;

    public function __construct(
        private ProviderCircuitBreaker $breaker
    ) {
        $this->providers = [
            new OpenAiDriver(),
            new GeminiDriver(),
        ];
    }

    public function firstAvailable()
    {
        foreach ($this->providers as $provider) {
            if ($provider->isAvailable() && !$this->breaker->isBlocked($provider->name())) {
                return $provider;
            }
        }

        return null;
    }
}
