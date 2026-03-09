<?php

namespace App\Services\ApiCache;

use App\Models\ApiQuery;
use App\Models\ApiRequestLog;
use App\Models\ApiResult;
use App\Models\User;
use App\Models\UserApiResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApiCacheService
{
    public function __construct(
        protected QueryHasher $hasher,
    ) {}

    /**
     * Resolve an API query -- return cached data or call the external API.
     *
     * @param  User        $user         Requesting user
     * @param  string      $apiProvider   e.g. 'dataforseo', 'serpapi', 'openai'
     * @param  string      $feature       e.g. 'keyword_analysis', 'backlinks'
     * @param  array       $queryParams   The normalizable query parameters
     * @param  callable    $apiFetcher    fn(): array  -- invoked on cache miss; must return the raw response array
     * @param  string|null $featureKey    Billing feature key (maps to features.key)
     * @return ApiCacheResult
     */
    public function resolve(
        User $user,
        string $apiProvider,
        string $feature,
        array $queryParams,
        callable $apiFetcher,
        ?string $featureKey = null,
    ): ApiCacheResult {
        $queryHash = $this->hasher->hash($apiProvider, $feature, $queryParams);
        $startMs = hrtime(true);

        // ── 1. Check for a valid cached result ─────────────────────

        $apiQuery = ApiQuery::byHash($queryHash)->first();

        if ($apiQuery) {
            $validResult = $apiQuery->latestValidResult();

            if ($validResult) {
                $alreadyLinked = $this->userAlreadyLinked($user, $validResult, $featureKey);

                $link = $this->linkResultToUser(
                    $user, $validResult, $featureKey, wasCacheHit: true, creditCharged: false,
                );

                $this->logRequest(
                    user: $user,
                    apiQuery: $apiQuery,
                    apiResult: $validResult,
                    apiProvider: $apiProvider,
                    feature: $feature,
                    wasCacheHit: true,
                    creditCharged: false,
                    requestPayload: $queryParams,
                    responseStatus: 200,
                    startMs: $startMs,
                );

                return new ApiCacheResult($validResult, wasCacheHit: true, creditCharged: false);
            }
        }

        // ── 2. Cache miss — acquire lock to prevent duplicate API calls ──

        $lockKey = "api_cache_lock:{$queryHash}";
        $lockTtl = config('api-cache.lock.ttl_seconds', 120);
        $lockWait = config('api-cache.lock.wait_seconds', 90);

        $lock = Cache::lock($lockKey, $lockTtl);

        try {
            $acquired = $lock->block($lockWait);

            if (! $acquired) {
                Log::warning('ApiCacheService: lock timeout, proceeding with API call', [
                    'query_hash' => $queryHash,
                    'provider' => $apiProvider,
                    'feature' => $feature,
                ]);
            }

            // Re-check after acquiring lock (another process may have populated the cache)
            if ($apiQuery) {
                $freshResult = $apiQuery->latestValidResult();
                if ($freshResult) {
                    $link = $this->linkResultToUser(
                        $user, $freshResult, $featureKey, wasCacheHit: true, creditCharged: false,
                    );

                    $this->logRequest(
                        user: $user,
                        apiQuery: $apiQuery,
                        apiResult: $freshResult,
                        apiProvider: $apiProvider,
                        feature: $feature,
                        wasCacheHit: true,
                        creditCharged: false,
                        requestPayload: $queryParams,
                        responseStatus: 200,
                        startMs: $startMs,
                    );

                    return new ApiCacheResult($freshResult, wasCacheHit: true, creditCharged: false);
                }
            }

            // ── 3. Call external API ───────────────────────────────────

            $responseData = $apiFetcher();

            // ── 4. Persist query + result ──────────────────────────────

            $cacheResult = DB::transaction(function () use (
                $user, $apiProvider, $feature, $queryHash, $queryParams,
                $responseData, $featureKey, $apiQuery, $startMs,
            ) {
                $apiQuery = $apiQuery ?? ApiQuery::create([
                    'api_provider' => $apiProvider,
                    'feature' => $feature,
                    'query_hash' => $queryHash,
                    'query_parameters' => $queryParams,
                ]);

                $apiResult = $this->storeResult($apiQuery, $apiProvider, $feature, $responseData);

                $this->linkResultToUser(
                    $user, $apiResult, $featureKey, wasCacheHit: false, creditCharged: true,
                );

                $this->logRequest(
                    user: $user,
                    apiQuery: $apiQuery,
                    apiResult: $apiResult,
                    apiProvider: $apiProvider,
                    feature: $feature,
                    wasCacheHit: false,
                    creditCharged: true,
                    requestPayload: $queryParams,
                    responseStatus: 200,
                    startMs: $startMs,
                );

                return new ApiCacheResult($apiResult, wasCacheHit: false, creditCharged: true);
            });

            return $cacheResult;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Resolve without a user context (system-level / background job).
     */
    public function resolveAnonymous(
        string $apiProvider,
        string $feature,
        array $queryParams,
        callable $apiFetcher,
    ): ApiCacheResult {
        $queryHash = $this->hasher->hash($apiProvider, $feature, $queryParams);

        $apiQuery = ApiQuery::byHash($queryHash)->first();

        if ($apiQuery) {
            $validResult = $apiQuery->latestValidResult();
            if ($validResult) {
                return new ApiCacheResult($validResult, wasCacheHit: true, creditCharged: false);
            }
        }

        $lockKey = "api_cache_lock:{$queryHash}";
        $lock = Cache::lock($lockKey, config('api-cache.lock.ttl_seconds', 120));

        try {
            $lock->block(config('api-cache.lock.wait_seconds', 90));

            if ($apiQuery) {
                $freshResult = $apiQuery->latestValidResult();
                if ($freshResult) {
                    return new ApiCacheResult($freshResult, wasCacheHit: true, creditCharged: false);
                }
            }

            $responseData = $apiFetcher();

            return DB::transaction(function () use ($apiQuery, $apiProvider, $feature, $queryHash, $queryParams, $responseData) {
                $apiQuery = $apiQuery ?? ApiQuery::create([
                    'api_provider' => $apiProvider,
                    'feature' => $feature,
                    'query_hash' => $queryHash,
                    'query_parameters' => $queryParams,
                ]);

                $apiResult = $this->storeResult($apiQuery, $apiProvider, $feature, $responseData);

                return new ApiCacheResult($apiResult, wasCacheHit: false, creditCharged: false);
            });
        } finally {
            $lock?->release();
        }
    }

    /**
     * Manually store a result for a known query (useful for background re-fetches).
     */
    public function storeResult(ApiQuery $apiQuery, string $apiProvider, string $feature, array $responseData): ApiResult
    {
        $json = json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $byteSize = strlen($json);
        $compressed = false;

        $compressionEnabled = config('api-cache.compression.enabled', true);
        $minBytes = config('api-cache.compression.min_size_bytes', 1024);

        if ($compressionEnabled && $byteSize >= $minBytes) {
            $payload = ApiResult::compressPayload($json);
            $compressed = true;
        } else {
            $payload = $json;
        }

        $retentionDays = $this->getRetentionDays($apiProvider, $feature);

        return ApiResult::create([
            'api_query_id' => $apiQuery->id,
            'response_payload' => $payload,
            'response_meta' => [
                'original_byte_size' => $byteSize,
                'compressed' => $compressed,
                'provider' => $apiProvider,
                'feature' => $feature,
            ],
            'is_compressed' => $compressed,
            'byte_size' => $byteSize,
            'fetched_at' => now(),
            'expires_at' => now()->addDays($retentionDays),
        ]);
    }

    /**
     * Check whether the user has already accessed a specific result for a given feature.
     */
    public function userAlreadyLinked(User $user, ApiResult $result, ?string $featureKey): bool
    {
        if (! $featureKey) {
            return false;
        }

        return UserApiResult::where('user_id', $user->id)
            ->where('api_result_id', $result->id)
            ->where('feature_key', $featureKey)
            ->exists();
    }

    /**
     * Determine whether the user should be charged. Returns false if the user
     * already accessed this exact result for the same feature.
     */
    public function shouldCharge(User $user, ApiResult $result, ?string $featureKey): bool
    {
        if (! $featureKey) {
            return false;
        }

        return ! $this->userAlreadyLinked($user, $result, $featureKey);
    }

    /**
     * Get cache hit statistics for a provider within a date range.
     */
    public function getStats(string $apiProvider, int $days = 30): array
    {
        $since = now()->subDays($days);

        $total = ApiRequestLog::forProvider($apiProvider)->where('created_at', '>=', $since)->count();
        $hits = ApiRequestLog::forProvider($apiProvider)->cacheHits()->where('created_at', '>=', $since)->count();

        return [
            'provider' => $apiProvider,
            'period_days' => $days,
            'total_requests' => $total,
            'cache_hits' => $hits,
            'cache_misses' => $total - $hits,
            'hit_rate' => $total > 0 ? round($hits / $total * 100, 2) : 0,
        ];
    }

    // ── Private helpers ────────────────────────────────────────────

    protected function linkResultToUser(
        User $user,
        ApiResult $result,
        ?string $featureKey,
        bool $wasCacheHit,
        bool $creditCharged,
    ): UserApiResult {
        $existing = UserApiResult::where('user_id', $user->id)
            ->where('api_result_id', $result->id)
            ->where('feature_key', $featureKey ?? 'unknown')
            ->first();

        if ($existing) {
            $existing->update([
                'accessed_at' => now(),
                'was_cache_hit' => $wasCacheHit,
                // Never overwrite a true charge with false — the user was already billed
                'credit_charged' => $existing->credit_charged || $creditCharged,
            ]);

            return $existing;
        }

        return UserApiResult::create([
            'user_id' => $user->id,
            'api_result_id' => $result->id,
            'feature_key' => $featureKey ?? 'unknown',
            'was_cache_hit' => $wasCacheHit,
            'credit_charged' => $creditCharged,
            'accessed_at' => now(),
        ]);
    }

    protected function logRequest(
        ?User $user,
        ApiQuery $apiQuery,
        ?ApiResult $apiResult,
        string $apiProvider,
        string $feature,
        bool $wasCacheHit,
        bool $creditCharged,
        ?array $requestPayload,
        ?int $responseStatus,
        int $startMs,
        ?string $errorMessage = null,
    ): ApiRequestLog {
        $elapsedMs = (int) ((hrtime(true) - $startMs) / 1_000_000);

        return ApiRequestLog::create([
            'user_id' => $user?->id,
            'api_query_id' => $apiQuery->id,
            'api_result_id' => $apiResult?->id,
            'api_provider' => $apiProvider,
            'feature' => $feature,
            'was_cache_hit' => $wasCacheHit,
            'credit_charged' => $creditCharged,
            'request_payload' => $requestPayload,
            'response_status' => $responseStatus,
            'response_time_ms' => $elapsedMs,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Log a failed API call (useful when the fetcher throws and you want to record the error).
     */
    public function logFailure(
        ?User $user,
        string $apiProvider,
        string $feature,
        array $queryParams,
        int $startMs,
        string $errorMessage,
        ?int $responseStatus = null,
    ): ApiRequestLog {
        $queryHash = $this->hasher->hash($apiProvider, $feature, $queryParams);
        $apiQuery = ApiQuery::byHash($queryHash)->first();

        $elapsed = (int) ((hrtime(true) - $startMs) / 1_000_000);

        return ApiRequestLog::create([
            'user_id' => $user?->id,
            'api_query_id' => $apiQuery?->id,
            'api_provider' => $apiProvider,
            'feature' => $feature,
            'was_cache_hit' => false,
            'credit_charged' => false,
            'request_payload' => $queryParams,
            'response_status' => $responseStatus,
            'response_time_ms' => $elapsed,
            'error_message' => $errorMessage,
        ]);
    }

    protected function getRetentionDays(string $apiProvider, string $feature): int
    {
        $providerConfig = config("api-cache.retention.{$apiProvider}", []);

        return $providerConfig[$feature]
            ?? $providerConfig['default']
            ?? 7;
    }
}
