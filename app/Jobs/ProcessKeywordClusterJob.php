<?php

namespace App\Jobs;

use App\Domain\Billing\Contracts\WalletServiceInterface;
use App\Models\ClusterJob;
use App\Models\KeywordClusterSnapshot;
use App\Models\UserKeywordClusterAccess;
use App\Services\Keyword\KeywordClusterEngineClient;
use App\Services\Keyword\KeywordClusterEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessKeywordClusterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 300;

    public $backoff = [30, 90];

    public function __construct(public int $jobId) {}

    public function handle(
        KeywordClusterEngineClient $client,
        KeywordClusterEngineService $engineService,
        WalletServiceInterface $walletService
    ): void {
        $job = ClusterJob::find($this->jobId);
        if (! $job) {
            Log::warning('Cluster job not found', ['job_id' => $this->jobId]);

            return;
        }

        if ($job->status === ClusterJob::STATUS_COMPLETED || $job->status === ClusterJob::STATUS_FAILED) {
            return;
        }

        try {
            $job->update([
                'status' => ClusterJob::STATUS_PROCESSING,
                'started_at' => now(),
            ]);

            $cacheKey = $job->cache_key;
            $computeLock = Cache::lock('keyword_cluster:compute:'.$cacheKey, 240);

            $computeLock->block(220, function () use ($job, $client, $engineService, $cacheKey) {
                $existing = KeywordClusterSnapshot::query()
                    ->valid()
                    ->forCacheKey($cacheKey)
                    ->first();

                if ($existing) {
                    $job->refresh();
                    $job->update([
                        'status' => ClusterJob::STATUS_COMPLETED,
                        'snapshot_id' => $existing->id,
                        'completed_at' => now(),
                    ]);

                    return;
                }

                $gl = $engineService->resolveGl($job->location_code);
                $schemaVersion = (int) config('services.keyword_clustering.tree_schema_version', 1);

                $payload = $client->fetchKeywordClusterTree(
                    $job->keyword,
                    $job->language_code,
                    $job->location_code,
                    $gl,
                    $schemaVersion
                );

                $expiresAt = now()->addDays($engineService->snapshotTtlDays());

                $snapshot = KeywordClusterSnapshot::query()->create([
                    'cache_key' => $cacheKey,
                    'keyword' => $job->keyword,
                    'language_code' => $job->language_code,
                    'location_code' => $job->location_code,
                    'tree_json' => $payload,
                    'expires_at' => $expiresAt,
                    'schema_version' => $schemaVersion,
                ]);

                $job->refresh();
                $job->update([
                    'status' => ClusterJob::STATUS_COMPLETED,
                    'snapshot_id' => $snapshot->id,
                    'completed_at' => now(),
                ]);
            });

            $job->refresh();
            if ($job->status === ClusterJob::STATUS_COMPLETED) {
                if ($job->credit_reservation_id) {
                    $walletService->completeReservation($job->credit_reservation_id);
                }
                UserKeywordClusterAccess::query()->firstOrCreate(
                    [
                        'user_id' => $job->user_id,
                        'cache_key' => $job->cache_key,
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::error('Cluster job failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);

            $job->refresh();
            if ($job->status === ClusterJob::STATUS_COMPLETED) {
                if ($job->credit_reservation_id) {
                    $walletService->completeReservation($job->credit_reservation_id);
                }
                throw $e;
            }

            if ($job->credit_reservation_id) {
                $walletService->reverseReservation($job->credit_reservation_id);
            }

            $job->update([
                'status' => ClusterJob::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }
}
