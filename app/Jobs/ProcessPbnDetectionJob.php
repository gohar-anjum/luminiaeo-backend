<?php

namespace App\Jobs;

use App\DTOs\BacklinkDTO;
use App\Exceptions\PbnDetectorException;
use App\Models\Backlink;
use App\Models\PbnDetection;
use App\Models\SeoTask;
use App\Repositories\DataForSEO\BacklinksRepository;
use App\Services\Pbn\PbnDetectorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPbnDetectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 120;

    public function __construct(
        public string $taskId,
        public string $domain,
        public array $backlinks,
        public array $summary
    ) {
        $this->onQueue('backlinks');
    }

    public function handle(PbnDetectorService $pbnDetector, BacklinksRepository $repository): void
    {
        Log::info('[PBN Job] Job started - ProcessPbnDetectionJob', [
            'task_id' => $this->taskId,
            'domain' => $this->domain,
            'backlinks_count' => count($this->backlinks),
            'has_summary' => !empty($this->summary),
            'summary_keys' => array_keys($this->summary),
        ]);

        $seoTask = SeoTask::where('task_id', $this->taskId)->first();
        
        if (!$seoTask) {
            Log::warning('[PBN Job] SEO task not found in database', [
                'task_id' => $this->taskId,
            ]);
            return;
        }

        Log::info('[PBN Job] SEO task found', [
            'task_id' => $this->taskId,
            'domain' => $seoTask->domain,
            'task_status' => $seoTask->status,
            'task_created_at' => $seoTask->created_at,
        ]);

        try {
            Log::info('[PBN Job] Checking if PBN detection should be processed', [
                'task_id' => $this->taskId,
                'domain' => $this->domain,
                'service_enabled' => $pbnDetector->enabled(),
            ]);
            
            if (!$this->shouldProcessPbn($pbnDetector)) {
                $reason = !$pbnDetector->enabled() ? 'service_not_enabled' : 'circuit_breaker_open';
                Log::warning('[PBN Job] PBN detection skipped - not processing', [
                    'task_id' => $this->taskId,
                    'domain' => $this->domain,
                    'reason' => $reason,
                ]);
                $repository->finalizeDetectionRecord($this->taskId, 'skipped');
                $existingResult = $seoTask->result ?? [];
                $finalResult = array_merge($existingResult, [
                    'summary' => $this->summary,
                    'pbn_detection' => ['status' => 'skipped', 'reason' => $reason],
                ]);
                Log::info('[PBN Job] Marking task as completed (skipped)', [
                    'task_id' => $this->taskId,
                ]);
                $seoTask->markAsCompleted($finalResult);
                return;
            }

            Log::info('[PBN Job] PBN detection approved - proceeding with analysis', [
                'task_id' => $this->taskId,
                'domain' => $this->domain,
            ]);

            Log::info('[PBN Job] Calling PBN detector service analyze() method', [
                'task_id' => $this->taskId,
                'domain' => $this->domain,
                'backlinks_count' => count($this->backlinks),
                'payload_size_bytes' => strlen(json_encode($this->backlinks)),
                'summary_count' => count($this->summary),
            ]);
            
            $jobStartTime = microtime(true);
            $detectionResponse = $pbnDetector->analyze($this->domain, $this->taskId, $this->backlinks, $this->summary);
            $jobDuration = round((microtime(true) - $jobStartTime) * 1000, 2);
            
            Log::info('[PBN Job] PBN detector service analyze() completed', [
                'task_id' => $this->taskId,
                'domain' => $this->domain,
                'analysis_duration_ms' => $jobDuration,
                'response_received' => !empty($detectionResponse),
                'response_keys' => array_keys($detectionResponse ?? []),
                'items_count' => count($detectionResponse['items'] ?? []),
                'has_summary' => isset($detectionResponse['summary']),
                'has_meta' => isset($detectionResponse['meta']),
            ]);

            if (!empty($detectionResponse)) {
                Log::info('[PBN Job] Detection response received - processing results', [
                    'task_id' => $this->taskId,
                    'domain' => $this->domain,
                    'items_count' => count($detectionResponse['items'] ?? []),
                    'summary' => $detectionResponse['summary'] ?? null,
                    'meta' => $detectionResponse['meta'] ?? null,
                ]);

                Log::info('[PBN Job] Loading backlinks from database', [
                    'task_id' => $this->taskId,
                ]);

                // Load backlinks from database and reconstruct DTOs
                $backlinkModels = Backlink::where('task_id', $this->taskId)->get();
                
                Log::info('[PBN Job] Backlinks loaded from database', [
                    'task_id' => $this->taskId,
                    'backlinks_count' => $backlinkModels->count(),
                ]);
                
                if ($backlinkModels->isEmpty()) {
                    Log::warning('[PBN Job] No backlinks found in database', [
                        'task_id' => $this->taskId,
                    ]);
                    $repository->finalizeDetectionRecord($this->taskId, 'failed', null, 'No backlinks found in database');
                    $seoTask->markAsFailed('No backlinks found in database');
                    return;
                }
                
                Log::info('[PBN Job] Reconstructing backlink DTOs from database models', [
                    'task_id' => $this->taskId,
                    'models_count' => $backlinkModels->count(),
                ]);

                $backlinkDtos = [];
                
                foreach ($backlinkModels as $index => $model) {
                    // Reconstruct DTO from model data
                    $dto = BacklinkDTO::fromArray([
                        'source_url' => $model->source_url,
                        'source_domain' => $model->source_domain,
                        'anchor' => $model->anchor,
                        'link_type' => $model->link_type,
                        'domain_rank' => $model->domain_rank,
                        'ip' => $model->ip,
                        'asn' => $model->asn,
                        'hosting_provider' => $model->hosting_provider,
                        'whois_registrar' => $model->whois_registrar,
                        'domain_age_days' => $model->domain_age_days,
                        'content_fingerprint' => $model->content_fingerprint,
                        'pbn_probability' => $model->pbn_probability,
                        'risk_level' => $model->risk_level,
                        'pbn_reasons' => is_string($model->pbn_reasons) ? json_decode($model->pbn_reasons, true) : $model->pbn_reasons,
                        'pbn_signals' => is_string($model->pbn_signals) ? json_decode($model->pbn_signals, true) : $model->pbn_signals,
                        'first_seen' => $model->first_seen,
                        'last_seen' => $model->last_seen,
                        'dofollow' => $model->link_type === 'dofollow',
                        'links_count' => $model->links_count,
                        'safe_browsing_status' => $model->safe_browsing_status,
                        'safe_browsing_threats' => is_string($model->safe_browsing_threats) ? json_decode($model->safe_browsing_threats, true) : $model->safe_browsing_threats,
                        'safe_browsing_checked_at' => $model->safe_browsing_checked_at,
                        'backlink_spam_score' => $model->backlink_spam_score,
                        'raw' => $model->raw ?? [],
                    ], $model->domain, $model->task_id);
                    $backlinkDtos[] = $dto;
                }
                
                Log::info('[PBN Job] DTOs reconstructed', [
                    'task_id' => $this->taskId,
                    'dtos_count' => count($backlinkDtos),
                ]);

                Log::info('[PBN Job] Applying detection results to DTOs', [
                    'task_id' => $this->taskId,
                    'detection_items_count' => count($detectionResponse['items'] ?? []),
                    'dtos_count' => count($backlinkDtos),
                ]);

                // Apply detection results to DTOs
                $repository->applyDetectionResults($backlinkDtos, $detectionResponse['items'] ?? []);

                Log::info('[PBN Job] Detection results applied - storing backlink DTOs in database', [
                    'task_id' => $this->taskId,
                    'dtos_count' => count($backlinkDtos),
                ]);

                $repository->storeBacklinkDtos($backlinkDtos, false);

                Log::info('[PBN Job] Backlink DTOs stored - finalizing detection record', [
                    'task_id' => $this->taskId,
                ]);

                $repository->finalizeDetectionRecord($this->taskId, 'completed', $detectionResponse);

                Log::info('[PBN Job] Detection record finalized', [
                    'task_id' => $this->taskId,
                    'status' => 'completed',
                ]);

                Log::info('[PBN Job] Building enriched backlinks for response', [
                    'task_id' => $this->taskId,
                    'backlink_models_count' => $backlinkModels->count(),
                ]);

                // Use the same models (already loaded) to build enriched backlinks for response
                $updatedBacklinkModels = $backlinkModels;
                $enrichedBacklinks = $updatedBacklinkModels->map(function ($model) {
                    return [
                        'source_url' => $model->source_url,
                        'source_domain' => $model->source_domain,
                        'anchor' => $model->anchor,
                        'link_type' => $model->link_type,
                        'domain_rank' => $model->domain_rank,
                        'ip' => $model->ip,
                        'asn' => $model->asn,
                        'hosting_provider' => $model->hosting_provider,
                        'whois_registrar' => $model->whois_registrar,
                        'domain_age_days' => $model->domain_age_days,
                        'content_fingerprint' => $model->content_fingerprint,
                        'pbn_probability' => $model->pbn_probability,
                        'risk_level' => $model->risk_level,
                        'pbn_reasons' => is_string($model->pbn_reasons) ? json_decode($model->pbn_reasons, true) : $model->pbn_reasons,
                        'pbn_signals' => is_string($model->pbn_signals) ? json_decode($model->pbn_signals, true) : $model->pbn_signals,
                        'safe_browsing_status' => $model->safe_browsing_status,
                        'safe_browsing_threats' => is_string($model->safe_browsing_threats) ? json_decode($model->safe_browsing_threats, true) : $model->safe_browsing_threats,
                        'backlink_spam_score' => $model->backlink_spam_score,
                        'first_seen' => $model->first_seen,
                        'last_seen' => $model->last_seen,
                        'dofollow' => $model->link_type === 'dofollow',
                        'links_count' => $model->links_count,
                    ];
                })->toArray();

                Log::info('[PBN Job] Enriched backlinks built', [
                    'task_id' => $this->taskId,
                    'enriched_backlinks_count' => count($enrichedBacklinks),
                ]);

                // Update result with enriched backlinks including PBN data
                $existingResult = $seoTask->result ?? [];
                $existingBacklinks = $existingResult['backlinks'] ?? [];
                
                Log::info('[PBN Job] Merging PBN data into existing backlinks', [
                    'task_id' => $this->taskId,
                    'existing_backlinks_items_count' => count($existingBacklinks['items'] ?? []),
                    'enriched_backlinks_count' => count($enrichedBacklinks),
                ]);
                
                // Create normalized lookup map for enriched backlinks
                $enrichedLookup = [];
                foreach ($enrichedBacklinks as $enriched) {
                    $normalizedUrl = rtrim(strtolower($enriched['source_url'] ?? ''), '/');
                    if ($normalizedUrl) {
                        $enrichedLookup[$normalizedUrl] = $enriched;
                    }
                }
                
                Log::info('[PBN Job] Enriched lookup map created', [
                    'task_id' => $this->taskId,
                    'lookup_map_size' => count($enrichedLookup),
                ]);
                
                if (isset($existingBacklinks['items']) && is_array($existingBacklinks['items'])) {
                    // Merge PBN data into existing items
                    $itemsWithPbn = [];
                    foreach ($existingBacklinks['items'] as $item) {
                        // Try multiple field names for source URL
                        $sourceUrl = $item['source_url'] ?? $item['url_from'] ?? $item['url_to'] ?? null;
                        if ($sourceUrl) {
                            $normalizedItemUrl = rtrim(strtolower($sourceUrl), '/');
                            $enriched = $enrichedLookup[$normalizedItemUrl] ?? null;
                            
                            if ($enriched) {
                                $item['pbn_probability'] = $enriched['pbn_probability'];
                                $item['risk_level'] = $enriched['risk_level'];
                                $item['pbn_reasons'] = $enriched['pbn_reasons'];
                                $item['pbn_signals'] = $enriched['pbn_signals'];
                                $item['ip'] = $enriched['ip'];
                                $item['asn'] = $enriched['asn'];
                                $item['hosting_provider'] = $enriched['hosting_provider'];
                                $item['whois_registrar'] = $enriched['whois_registrar'];
                                $item['domain_age_days'] = $enriched['domain_age_days'];
                                $item['safe_browsing_status'] = $enriched['safe_browsing_status'];
                                $item['safe_browsing_threats'] = $enriched['safe_browsing_threats'];
                            }
                        }
                        $itemsWithPbn[] = $item;
                    }
                    $existingBacklinks['items'] = $itemsWithPbn;
                }

                Log::info('[PBN Job] Building final result structure', [
                    'task_id' => $this->taskId,
                ]);

                // Preserve existing result structure and only update what we need
                $finalResult = array_merge($existingResult, [
                    'backlinks' => $existingBacklinks,
                    'summary' => $this->summary,
                    'pbn_detection' => $detectionResponse,
                ]);
                
                Log::info('[PBN Job] Marking SEO task as completed', [
                    'task_id' => $this->taskId,
                    'has_backlinks' => !empty($finalResult['backlinks']),
                    'has_summary' => !empty($finalResult['summary']),
                    'has_pbn_detection' => !empty($finalResult['pbn_detection']),
                ]);

                $seoTask->markAsCompleted($finalResult);

                Log::info('[PBN Job] Resetting circuit breaker on success', [
                    'task_id' => $this->taskId,
                ]);

                // Reset circuit breaker on success
                $this->resetCircuitBreaker();

                Log::info('[PBN Job] Job completed successfully', [
                    'task_id' => $this->taskId,
                    'domain' => $this->domain,
                    'total_items_processed' => count($detectionResponse['items'] ?? []),
                ]);
            } else {
                $repository->finalizeDetectionRecord($this->taskId, 'skipped');
                // Preserve existing result structure
                $existingResult = $seoTask->result ?? [];
                $finalResult = array_merge($existingResult, [
                    'summary' => $this->summary,
                    'pbn_detection' => ['status' => 'skipped'],
                ]);
                $seoTask->markAsCompleted($finalResult);
            }
        } catch (PbnDetectorException $e) {
            Log::error('[PBN Job] PBN detection failed with PbnDetectorException', [
                'task_id' => $this->taskId,
                'domain' => $this->domain,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
                'http_status' => $e->getStatusCode(),
            ]);

            Log::info('[PBN Job] Recording circuit breaker failure', [
                'task_id' => $this->taskId,
            ]);

            // Record failure in circuit breaker
            $this->recordCircuitBreakerFailure();

            Log::info('[PBN Job] Finalizing detection record as failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            $repository->finalizeDetectionRecord($this->taskId, 'failed', null, $e->getMessage());
            
            Log::info('[PBN Job] Completing task with failed PBN detection', [
                'task_id' => $this->taskId,
            ]);
            
            // Complete task without PBN detection (preserve existing result)
            $existingResult = $seoTask->result ?? [];
            $finalResult = array_merge($existingResult, [
                'summary' => $this->summary,
                'pbn_detection' => ['status' => 'failed', 'error' => $e->getMessage()],
            ]);
            $seoTask->markAsCompleted($finalResult);

            Log::info('[PBN Job] Job completed with failure status', [
                'task_id' => $this->taskId,
                'domain' => $this->domain,
            ]);
        } catch (\Exception $e) {
            Log::error('[PBN Job] Unexpected error in PBN detection job', [
                'task_id' => $this->taskId,
                'domain' => $this->domain,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            Log::info('[PBN Job] Recording circuit breaker failure', [
                'task_id' => $this->taskId,
            ]);

            // Record failure in circuit breaker
            $this->recordCircuitBreakerFailure();

            Log::info('[PBN Job] Finalizing detection record as failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);

            // Finalize detection record
            $repository->finalizeDetectionRecord($this->taskId, 'failed', null, $e->getMessage());
            
            // Mark task as failed to prevent it from staying in processing state
            if ($seoTask) {
                try {
                    Log::info('[PBN Job] Marking SEO task as failed', [
                        'task_id' => $this->taskId,
                    ]);
                    $seoTask->markAsFailed($e->getMessage());
                } catch (\Exception $markFailedException) {
                    Log::error('[PBN Job] Failed to mark task as failed', [
                        'task_id' => $this->taskId,
                        'error' => $markFailedException->getMessage(),
                    ]);
                }
            }

            Log::info('[PBN Job] Job completed with exception', [
                'task_id' => $this->taskId,
                'domain' => $this->domain,
            ]);
        }
    }

    protected function shouldProcessPbn(PbnDetectorService $pbnDetector): bool
    {
        if (!$pbnDetector->enabled()) {
            return false;
        }

        $circuitBreakerKey = 'pbn_detector:circuit_breaker';
        $failureCount = \Cache::get($circuitBreakerKey . ':failures', 0);
        $lastFailure = \Cache::get($circuitBreakerKey . ':last_failure');

        // Circuit breaker opens after 5 consecutive failures
        if ($failureCount >= 5) {
            // Check if we should try again (after 10 minutes)
            if ($lastFailure && now()->diffInMinutes($lastFailure) < 10) {
                return false;
            }
            // Reset after cooldown period
            \Cache::forget($circuitBreakerKey . ':failures');
        }

        return true;
    }

    protected function recordCircuitBreakerFailure(): void
    {
        $circuitBreakerKey = 'pbn_detector:circuit_breaker';
        $failures = \Cache::increment($circuitBreakerKey . ':failures', 1);
        \Cache::put($circuitBreakerKey . ':last_failure', now(), 600); // 10 minutes
    }

    protected function resetCircuitBreaker(): void
    {
        $circuitBreakerKey = 'pbn_detector:circuit_breaker';
        \Cache::forget($circuitBreakerKey . ':failures');
        \Cache::forget($circuitBreakerKey . ':last_failure');
    }
}

