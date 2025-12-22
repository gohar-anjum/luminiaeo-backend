<?php

namespace App\Services\LLM\Drivers\Contracts;

interface LLMProviderInterface
{
    public function name(): string;

    public function send(array $messages): array;

    public function isAvailable(): bool;
}
