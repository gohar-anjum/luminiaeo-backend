<?php

namespace App\Console\Commands;

use App\Services\Keyword\KeywordCacheService;
use App\Services\Keyword\KeywordClusteringCacheService;
use Illuminate\Console\Command;

class RefreshKeywordCache extends Command
{
    protected $signature = 'keyword-cache:refresh
                            {--expired : Only refresh expired entries}
                            {--expiring : Refresh entries expiring within 7 days}
                            {--cleanup : Clean up expired entries}
                            {--clusters : Refresh cluster cache}
                            {--batch-size=100 : Batch size for processing}';

    protected $description = 'Refresh keyword cache entries and manage expiration';

    public function handle(
        KeywordCacheService $cacheService,
        KeywordClusteringCacheService $clusteringService
    ): int {
        $this->info('Starting keyword cache refresh...');

        $refreshed = 0;
        $cleaned = 0;

        if ($this->option('cleanup') || $this->option('expired')) {
            $this->info('Cleaning up expired cache entries...');
            $cleaned = $cacheService->cleanupExpiredCache();
            $this->info("Cleaned up {$cleaned} expired entries.");
        }

        if ($this->option('expired')) {
            $this->info('Refreshing expired cache entries...');
            $refreshed = $cacheService->refreshExpiredCache((int) $this->option('batch-size'));
            $this->info("Refreshed {$refreshed} expired entries.");
        }

        if ($this->option('expiring')) {
            $this->info('Refreshing expiring cache entries...');
            $refreshed += $cacheService->refreshExpiredCache((int) $this->option('batch-size'));
            $this->info("Refreshed {$refreshed} expiring entries.");
        }

        if ($this->option('clusters')) {
            $this->info('Refreshing cluster cache...');
            $clusterRefreshed = $clusteringService->refreshClusterCache((int) $this->option('batch-size'));
            $this->info("Refreshed {$clusterRefreshed} clusters.");
        }

        if (!$this->option('expired') && !$this->option('expiring') && !$this->option('cleanup') && !$this->option('clusters')) {
            $this->info('Refreshing expired cache entries (default behavior)...');
            $refreshed = $cacheService->refreshExpiredCache((int) $this->option('batch-size'));
            $this->info("Refreshed {$refreshed} expired entries.");
        }

        $this->info('Keyword cache refresh completed!');

        return Command::SUCCESS;
    }
}
