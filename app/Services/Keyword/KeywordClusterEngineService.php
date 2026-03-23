<?php

namespace App\Services\Keyword;

use App\Jobs\ProcessKeywordClusterJob;
use App\Models\ClusterJob;
use App\Models\KeywordClusterSnapshot;
use App\Services\LocationCodeService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;

class KeywordClusterEngineService
{
    public function __construct(
        protected LocationCodeService $locationCodeService
    ) {}

    /**
     * @return array{hit: bool, snapshot: ?KeywordClusterSnapshot, job: ?ClusterJob, lock_timeout?: bool, duplicate_job?: bool}
     */
    public function requestCluster(
        string $keyword,
        string $languageCode,
        int $locationCode,
        ?int $creditReservationId = null
    ): array {
        $userId = Auth::id();
        if (! $userId) {
            throw new RuntimeException('Unauthenticated.');
        }

        $normalized = $this->normalizeKeyword($keyword);
        $languageCode = strtolower($languageCode);
        $cacheKey = $this->cacheKey($normalized, $languageCode, $locationCode);

        $snapshot = KeywordClusterSnapshot::query()
            ->valid()
            ->forCacheKey($cacheKey)
            ->first();

        if ($snapshot) {
            return ['hit' => true, 'snapshot' => $snapshot, 'job' => null];
        }

        $lockKey = 'keyword_cluster:lock:'.$cacheKey;
        $lockTtl = (int) config('cache_locks.keyword_cluster.timeout', 300);
        $waitSeconds = (int) config('cache_locks.keyword_cluster.wait_seconds', 90);

        $lock = Cache::lock($lockKey, $lockTtl);

        try {
            $result = $lock->block($waitSeconds, function () use ($userId, $normalized, $languageCode, $locationCode, $cacheKey, $creditReservationId) {
                $snapshot = KeywordClusterSnapshot::query()
                    ->valid()
                    ->forCacheKey($cacheKey)
                    ->first();

                if ($snapshot) {
                    return ['hit' => true, 'snapshot' => $snapshot, 'job' => null];
                }

                $existingJob = ClusterJob::query()
                    ->where('user_id', $userId)
                    ->where('cache_key', $cacheKey)
                    ->whereIn('status', [ClusterJob::STATUS_PENDING, ClusterJob::STATUS_PROCESSING])
                    ->orderByDesc('id')
                    ->first();

                if ($existingJob) {
                    return [
                        'hit' => false,
                        'snapshot' => null,
                        'job' => $existingJob,
                        'duplicate_job' => true,
                    ];
                }

                $job = ClusterJob::query()->create([
                    'user_id' => $userId,
                    'credit_reservation_id' => $creditReservationId,
                    'cache_key' => $cacheKey,
                    'keyword' => $normalized,
                    'language_code' => $languageCode,
                    'location_code' => $locationCode,
                    'status' => ClusterJob::STATUS_PENDING,
                ]);

                ProcessKeywordClusterJob::dispatch($job->id);

                Log::info('Keyword cluster job dispatched', [
                    'job_id' => $job->id,
                    'cache_key' => $cacheKey,
                ]);

                return ['hit' => false, 'snapshot' => null, 'job' => $job];
            });
        } catch (LockTimeoutException $e) {
            Log::warning('Keyword cluster lock timeout', ['cache_key' => $cacheKey]);

            return [
                'hit' => false,
                'snapshot' => null,
                'job' => null,
                'lock_timeout' => true,
            ];
        }

        return $result;
    }

    public function resolveGl(int $locationCode): string
    {
        $iso = $this->locationCodeService->getCountryIsoCode($locationCode);

        return $iso ?: 'us';
    }

    public function cacheKey(string $normalizedKeyword, string $languageCode, int $locationCode): string
    {
        try {
            $payload = json_encode([
                'kw' => $normalizedKeyword,
                'lc' => strtolower($languageCode),
                'loc' => $locationCode,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            $payload = $normalizedKeyword.'|'.$languageCode.'|'.$locationCode;
        }

        return hash('sha256', $payload);
    }

    public function normalizeKeyword(string $keyword): string
    {
        $keyword = trim($keyword);
        if (strlen($keyword) > 255) {
            $keyword = substr($keyword, 0, 255);
        }

        return $keyword;
    }

    public function snapshotTtlDays(): int
    {
        return max(1, (int) config('services.keyword_clustering.snapshot_ttl_days', 7));
    }
}
