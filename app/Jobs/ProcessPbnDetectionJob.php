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
        $seoTask = SeoTask::where('task_id', $this->taskId)->first();
        
        if (!$seoTask) {
            Log::warning('SEO task not found for PBN detection', ['task_id' => $this->taskId]);
            return;
        }

        try {
            // Check circuit breaker (if PBN service has failed recently, skip)
            try {
                Log::info('PBN detection job started', [
                    'task_id' => $this->taskId,
                    'domain' => $this->domain,
                    'backlinks_count' => count($this->backlinks),
                    'service_enabled' => $pbnDetector->enabled(),
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors
            }
            
            if (!$this->shouldProcessPbn($pbnDetector)) {
                $reason = !$pbnDetector->enabled() ? 'service_not_enabled' : 'circuit_breaker_open';
                try {
                    Log::warning('PBN detection skipped', [
                        'task_id' => $this->taskId,
                        'domain' => $this->domain,
                        'reason' => $reason,
                    ]);
                } catch (\Exception $logError) {
                    // Silently ignore logging errors
                }
                $repository->finalizeDetectionRecord($this->taskId, 'skipped');
                $existingResult = $seoTask->result ?? [];
                $finalResult = array_merge($existingResult, [
                    'summary' => $this->summary,
                    'pbn_detection' => ['status' => 'skipped', 'reason' => $reason],
                ]);
                $seoTask->markAsCompleted($finalResult);
                return;
            }

            // Run PBN detection
            try {
                Log::info('Calling PBN detector microservice', [
                    'task_id' => $this->taskId,
                    'domain' => $this->domain,
                    'backlinks_count' => count($this->backlinks),
                    'payload_size' => strlen(json_encode($this->backlinks)),
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors
            }
            
            $detectionResponse = $pbnDetector->analyze($this->domain, $this->taskId, $this->backlinks, $this->summary);
            
            try {
                Log::info('PBN detector microservice responded', [
                    'task_id' => $this->taskId,
                    'domain' => $this->domain,
                    'response_keys' => array_keys($detectionResponse),
                    'items_count' => count($detectionResponse['items'] ?? []),
                    'has_summary' => isset($detectionResponse['summary']),
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors
            }

            if (!empty($detectionResponse)) {
                // Load backlinks from database and reconstruct DTOs
                $backlinkModels = Backlink::where('task_id', $this->taskId)->get();
                
                if ($backlinkModels->isEmpty()) {
                    Log::warning('No backlinks found in database for PBN detection', [
                        'task_id' => $this->taskId,
                    ]);
                    $repository->finalizeDetectionRecord($this->taskId, 'failed', null, 'No backlinks found in database');
                    $seoTask->markAsFailed('No backlinks found in database');
                    return;
                }
                
                $backlinkDtos = [];
                
                foreach ($backlinkModels as $model) {
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
                
                // Apply detection results to DTOs
                $repository->applyDetectionResults($backlinkDtos, $detectionResponse['items'] ?? []);
                $repository->storeBacklinkDtos($backlinkDtos, false);
                $repository->finalizeDetectionRecord($this->taskId, 'completed', $detectionResponse);

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

                // Update result with enriched backlinks including PBN data
                $existingResult = $seoTask->result ?? [];
                $existingBacklinks = $existingResult['backlinks'] ?? [];
                
                // Create normalized lookup map for enriched backlinks
                $enrichedLookup = [];
                foreach ($enrichedBacklinks as $enriched) {
                    $normalizedUrl = rtrim(strtolower($enriched['source_url'] ?? ''), '/');
                    if ($normalizedUrl) {
                        $enrichedLookup[$normalizedUrl] = $enriched;
                    }
                }
                
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

                // Preserve existing result structure and only update what we need
                $finalResult = array_merge($existingResult, [
                    'backlinks' => $existingBacklinks,
                    'summary' => $this->summary,
                    'pbn_detection' => $detectionResponse,
                ]);
                
                $seoTask->markAsCompleted($finalResult);

                // Reset circuit breaker on success
                $this->resetCircuitBreaker();
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
            Log::error('PBN detection failed', [
                'task_id' => $this->taskId,
                'domain' => $this->domain,
                'error' => $e->getMessage(),
            ]);

            // Record failure in circuit breaker
            $this->recordCircuitBreakerFailure();

            $repository->finalizeDetectionRecord($this->taskId, 'failed', null, $e->getMessage());
            
            // Complete task without PBN detection (preserve existing result)
            $existingResult = $seoTask->result ?? [];
            $finalResult = array_merge($existingResult, [
                'summary' => $this->summary,
                'pbn_detection' => ['status' => 'failed', 'error' => $e->getMessage()],
            ]);
            $seoTask->markAsCompleted($finalResult);
        } catch (\Exception $e) {
            Log::error('Unexpected error in PBN detection job', [
                'task_id' => $this->taskId,
                'domain' => $this->domain,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Record failure in circuit breaker
            $this->recordCircuitBreakerFailure();

            // Finalize detection record
            $repository->finalizeDetectionRecord($this->taskId, 'failed', null, $e->getMessage());
            
            // Mark task as failed to prevent it from staying in processing state
            if ($seoTask) {
                try {
                    $seoTask->markAsFailed($e->getMessage());
                } catch (\Exception $markFailedException) {
                    Log::error('Failed to mark task as failed', [
                        'task_id' => $this->taskId,
                        'error' => $markFailedException->getMessage(),
                    ]);
                }
            }
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

