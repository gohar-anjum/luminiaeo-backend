<?php

namespace App\Services\ApiCache;

use App\Models\ApiResult;

/**
 * Immutable value object returned by ApiCacheService::resolve().
 *
 * Callers can inspect whether the data came from cache and retrieve the
 * decoded payload without knowing anything about compression.
 */
class ApiCacheResult
{
    public function __construct(
        public readonly ApiResult $result,
        public readonly bool $wasCacheHit,
        public readonly bool $creditCharged,
    ) {}

    public function payload(): array
    {
        return $this->result->getPayload();
    }

    public function resultId(): int
    {
        return $this->result->id;
    }

    public function expiresAt(): \DateTimeInterface
    {
        return $this->result->expires_at;
    }
}
