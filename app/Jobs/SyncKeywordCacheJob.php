<?php

namespace App\Jobs;

use App\Services\Keyword\KeywordCacheService;
use App\Services\Keyword\KeywordClusteringCacheService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncKeywordCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;
    public $backoff = [60, 300, 600];

    public function __construct(
        public ?array $keywords = null,
        public string $languageCode = 'en',
        public int $locationCode = 2840,
        public string $source = 'serp_api',
        public bool $refreshExpired = false,
        public bool $refreshClusters = false
    ) {
    }

    public function handle(
        KeywordCacheService $cacheService,
        KeywordClusteringCacheService $clusteringService
    ): void {
        try {
            Log::info('Starting keyword cache sync job', [
                'keywords_count' => $this->keywords ? count($this->keywords) : null,
                'language_code' => $this->languageCode,
                'location_code' => $this->locationCode,
                'source' => $this->source,
                'refresh_expired' => $this->refreshExpired,
                'refresh_clusters' => $this->refreshClusters,
            ]);

            if ($this->refreshExpired) {
                $refreshed = $cacheService->refreshExpiredCache(100);
                Log::info('Refreshed expired cache entries', [
                    'count' => $refreshed,
                ]);
            }

            if ($this->refreshClusters) {
                $clusterRefreshed = $clusteringService->refreshClusterCache(50);
                Log::info('Refreshed cluster cache', [
                    'count' => $clusterRefreshed,
                ]);
            }

            if ($this->keywords && !empty($this->keywords)) {
                $keywordData = $cacheService->getKeywordData(
                    $this->keywords,
                    $this->languageCode,
                    $this->locationCode,
                    $this->source
                );

                Log::info('Synced keyword data', [
                    'keywords_count' => count($this->keywords),
                    'results_count' => count($keywordData),
                ]);
            }

            Log::info('Keyword cache sync job completed successfully');
        } catch (\Throwable $e) {
            Log::error('Keyword cache sync job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Keyword cache sync job failed permanently', [
            'error' => $exception->getMessage(),
            'keywords' => $this->keywords,
        ]);
    }
}
