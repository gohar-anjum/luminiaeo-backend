<?php

namespace App\Console\Commands;

use App\Models\ApiQuery;
use App\Models\ApiRequestLog;
use App\Models\ApiResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeExpiredApiCache extends Command
{
    protected $signature = 'api-cache:purge
        {--logs : Also purge old request logs beyond the configured retention}
        {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Delete expired API cache results and optionally old request logs';

    public function handle(): int
    {
        $batchSize = config('api-cache.cleanup.batch_size', 1000);
        $keepOrphanQueries = config('api-cache.cleanup.keep_orphan_queries', false);
        $keepLogsDays = config('api-cache.cleanup.keep_logs_days', 90);
        $dryRun = $this->option('dry-run');

        $this->info('API Cache Purge' . ($dryRun ? ' [DRY RUN]' : ''));
        $this->newLine();

        // ── 1. Purge expired results ───────────────────────────────

        $expiredCount = ApiResult::expired()->count();
        $this->info("Expired API results found: {$expiredCount}");

        if ($expiredCount > 0 && ! $dryRun) {
            $deleted = 0;
            ApiResult::expired()
                ->select('id')
                ->chunkById($batchSize, function ($results) use (&$deleted) {
                    $ids = $results->pluck('id')->toArray();
                    DB::table('user_api_results')->whereIn('api_result_id', $ids)->delete();
                    ApiResult::whereIn('id', $ids)->delete();
                    $deleted += count($ids);
                });

            $this->info("  Deleted {$deleted} expired results (and associated user links).");
        }

        // ── 2. Clean orphan queries (no remaining results) ────────

        if (! $keepOrphanQueries) {
            $orphanCount = ApiQuery::whereDoesntHave('results')->count();
            $this->info("Orphan API queries found: {$orphanCount}");

            if ($orphanCount > 0 && ! $dryRun) {
                $deleted = 0;
                ApiQuery::whereDoesntHave('results')
                    ->select('id')
                    ->chunkById($batchSize, function ($queries) use (&$deleted) {
                        $ids = $queries->pluck('id')->toArray();
                        ApiQuery::whereIn('id', $ids)->delete();
                        $deleted += count($ids);
                    });

                $this->info("  Deleted {$deleted} orphan queries.");
            }
        }

        // ── 3. Purge old request logs (if --logs flag) ─────────────

        if ($this->option('logs')) {
            $cutoff = now()->subDays($keepLogsDays);
            $oldLogCount = ApiRequestLog::where('created_at', '<', $cutoff)->count();
            $this->info("Request logs older than {$keepLogsDays} days: {$oldLogCount}");

            if ($oldLogCount > 0 && ! $dryRun) {
                $deleted = 0;
                while (true) {
                    $batch = ApiRequestLog::where('created_at', '<', $cutoff)
                        ->limit($batchSize)
                        ->delete();

                    if ($batch === 0) {
                        break;
                    }
                    $deleted += $batch;
                }

                $this->info("  Deleted {$deleted} old request logs.");
            }
        }

        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
