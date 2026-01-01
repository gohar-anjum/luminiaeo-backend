<?php

namespace App\Console\Commands;

use App\DTOs\BacklinkDTO;
use App\Exceptions\PbnDetectorException;
use App\Models\Backlink;
use App\Models\PbnDetection;
use App\Models\SeoTask;
use App\Repositories\DataForSEO\BacklinksRepository;
use App\Services\DataForSEO\BacklinksService;
use App\Services\Pbn\PbnDetectorService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessBacklinkTasks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backlinks:process-pending 
                            {--limit=50 : Maximum number of tasks to process}
                            {--force : Force re-processing even if task is old}
                            {--skip-enrichment : Skip WHOIS and Safe Browsing enrichment}
                            {--max-age=24 : Maximum age in hours for pending/processing tasks}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all pending or in-progress backlink tasks';

    protected BacklinksRepository $repository;
    protected BacklinksService $backlinksService;
    protected PbnDetectorService $pbnDetector;

    public function __construct(
        BacklinksRepository $repository,
        BacklinksService $backlinksService,
        PbnDetectorService $pbnDetector
    ) {
        parent::__construct();
        $this->repository = $repository;
        $this->backlinksService = $backlinksService;
        $this->pbnDetector = $pbnDetector;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $force = $this->option('force');
        $skipEnrichment = $this->option('skip-enrichment');
        $maxAgeHours = (int) $this->option('max-age');

        $this->info("Processing pending/processing backlink tasks...");
        $this->info("Limit: {$limit}, Max Age: {$maxAgeHours} hours, Force: " . ($force ? 'Yes' : 'No'));

        // Query tasks
        $query = SeoTask::where('type', SeoTask::TYPE_BACKLINKS)
            ->whereIn('status', [SeoTask::STATUS_PENDING, SeoTask::STATUS_PROCESSING])
            ->orderBy('created_at', 'asc');

        if (!$force) {
            $query->where('created_at', '>=', now()->subHours($maxAgeHours));
        }

        $tasks = $query->limit($limit)->get();

        if ($tasks->isEmpty()) {
            $this->info("No tasks found to process.");
            return Command::SUCCESS;
        }

        $this->info("Found {$tasks->count()} task(s) to process.");

        $processed = 0;
        $failed = 0;
        $skipped = 0;

        $progressBar = $this->output->createProgressBar($tasks->count());
        $progressBar->start();

        foreach ($tasks as $task) {
            try {
                $result = $this->processTask($task, $skipEnrichment, $maxAgeHours);
                
                if ($result === 'skipped') {
                    $skipped++;
                } elseif ($result === 'success') {
                    $processed++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
                try {
                    Log::error('Failed to process backlink task', [
                        'task_id' => $task->task_id,
                        'domain' => $task->domain,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                } catch (\Exception $logError) {
                    // Silently ignore logging errors
                }
                
                // Mark task as failed if it's been stuck for too long
                if ($task->created_at->diffInHours(now()) > $maxAgeHours * 2) {
                    $task->markAsFailed('Task processing failed: ' . $e->getMessage());
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Processing complete!");
        $this->table(
            ['Status', 'Count'],
            [
                ['Processed', $processed],
                ['Failed', $failed],
                ['Skipped', $skipped],
                ['Total', $tasks->count()],
            ]
        );

        return Command::SUCCESS;
    }

    protected function processTask(SeoTask $task, bool $skipEnrichment, int $maxAgeHours = 24): string
    {
        // Check if task has backlinks in database
        $backlinkModels = Backlink::where('task_id', $task->task_id)->get();

        if ($backlinkModels->isEmpty()) {
            try {
                Log::warning('Backlink task has no backlinks in database', [
                    'task_id' => $task->task_id,
                    'domain' => $task->domain,
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors
            }
            
            // If task is old and has no backlinks, mark as failed
            if ($task->created_at->diffInHours(now()) > 48) {
                $task->markAsFailed('No backlinks found in database after 48 hours');
                return 'failed';
            }
            
            return 'skipped';
        }

        // Convert Backlink models to DTOs
        $backlinkDtos = $backlinkModels->map(function (Backlink $backlink) use ($task) {
            return BacklinkDTO::fromArray($backlink->toArray(), $task->domain, $task->task_id);
        })->all();

        // Re-enrich with WHOIS and Safe Browsing if needed
        if (!$skipEnrichment) {
            try {
                // Use reflection to call protected methods, or make them public
                $reflection = new \ReflectionClass($this->repository);
                
                $whoisMethod = $reflection->getMethod('enrichWithWhoisBatch');
                $whoisMethod->setAccessible(true);
                $whoisMethod->invoke($this->repository, $backlinkDtos);
                
                $safeBrowsingMethod = $reflection->getMethod('enrichWithSafeBrowsing');
                $safeBrowsingMethod->setAccessible(true);
                $safeBrowsingMethod->invoke($this->repository, $backlinkDtos);
                
                // Update backlinks with enrichment data
                $storeMethod = $reflection->getMethod('storeBacklinkDtos');
                $storeMethod->setAccessible(true);
                
                DB::transaction(function () use ($storeMethod, $backlinkDtos) {
                    $storeMethod->invoke($this->repository, $backlinkDtos, false);
                });
            } catch (\Exception $e) {
                try {
                    Log::warning('Failed to enrich backlinks, continuing anyway', [
                        'task_id' => $task->task_id,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Exception $logError) {
                    // Silently ignore logging errors
                }
            }
        }

        // Get summary from task result or fetch new one
        $summary = $task->result['summary'] ?? [];
        if (empty($summary) && !empty($task->domain)) {
            try {
                $summary = $this->backlinksService->getBacklinksSummary($task->domain);
            } catch (\Exception $e) {
                try {
                    Log::warning('Failed to fetch summary, using empty summary', [
                        'task_id' => $task->task_id,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Exception $logError) {
                    // Silently ignore logging errors
                }
                $summary = [];
            }
        }

        // Format detection payload
        $reflection = new \ReflectionClass($this->repository);
        $formatMethod = $reflection->getMethod('formatDetectionPayload');
        $formatMethod->setAccessible(true);
        $detectionPayload = $formatMethod->invoke($this->repository, $backlinkDtos);

        // Check if PBN detection record exists
        $pbnDetection = PbnDetection::where('task_id', $task->task_id)->first();
        
        if ($pbnDetection && in_array($pbnDetection->status, ['completed', 'failed'])) {
            // Already processed, skip
            try {
                Log::info('PBN detection already completed for task', [
                    'task_id' => $task->task_id,
                    'pbn_status' => $pbnDetection->status,
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors
            }
            return 'skipped';
        }

        // Update or create PBN detection record
        PbnDetection::updateOrCreate(
            ['task_id' => $task->task_id],
            [
                'domain' => $task->domain,
                'status' => 'pending',
                'analysis_started_at' => now(),
            ]
        );

        // Process synchronously - complete the full cycle
        try {
            $task->markAsProcessing();
            
            try {
                Log::info('Processing PBN detection synchronously', [
                    'task_id' => $task->task_id,
                    'domain' => $task->domain,
                    'backlinks_count' => count($backlinkDtos),
                ]);
            } catch (\Exception $logError) {
                // Silently ignore logging errors
            }

            // Check if PBN service is enabled
            if (!$this->pbnDetector->enabled()) {
                throw new PbnDetectorException('PBN detector service is not enabled', 503, 'SERVICE_NOT_CONFIGURED');
            }

            // PBN microservice has a limit of 10 backlinks per request
            // Split into batches and process sequentially
            $maxBacklinksPerRequest = (int) env('PBN_MAX_BACKLINKS', 10);
            $allDetectionItems = [];
            $allSummaries = [];
            
            $batches = array_chunk($detectionPayload, $maxBacklinksPerRequest);
            
            Log::info('[PBN Batch] Starting batch processing for PBN detection', [
                    'task_id' => $task->task_id,
                    'domain' => $task->domain,
                    'total_backlinks' => count($detectionPayload),
                    'batch_size' => $maxBacklinksPerRequest,
                    'number_of_batches' => count($batches),
                ]);

                foreach ($batches as $batchIndex => $batch) {
                    $batchTaskId = $task->task_id . '_batch_' . ($batchIndex + 1);
                    
                    Log::info('[PBN Batch] Processing batch', [
                        'task_id' => $task->task_id,
                        'batch_task_id' => $batchTaskId,
                        'batch_number' => $batchIndex + 1,
                        'total_batches' => count($batches),
                        'batch_size' => count($batch),
                        'batch_backlinks_sample' => array_slice(array_column($batch, 'source_url'), 0, 3),
                    ]);

                    try {
                        Log::info('[PBN Batch] Calling PBN detector analyze() for batch', [
                            'task_id' => $task->task_id,
                            'batch_task_id' => $batchTaskId,
                            'batch_number' => $batchIndex + 1,
                            'domain' => $task->domain,
                            'batch_size' => count($batch),
                        ]);

                        $batchStartTime = microtime(true);
                        // Call PBN microservice for this batch
                        $batchResponse = $this->pbnDetector->analyze(
                            $task->domain,
                            $batchTaskId,
                            $batch,
                            $summary
                        );
                        $batchDuration = round((microtime(true) - $batchStartTime) * 1000, 2);

                        Log::info('[PBN Batch] Batch analysis completed', [
                            'task_id' => $task->task_id,
                            'batch_task_id' => $batchTaskId,
                            'batch_number' => $batchIndex + 1,
                            'duration_ms' => $batchDuration,
                            'response_received' => !empty($batchResponse),
                            'items_count' => count($batchResponse['items'] ?? []),
                            'has_summary' => isset($batchResponse['summary']),
                        ]);

                        if (!empty($batchResponse)) {
                            // Collect items from this batch
                            if (isset($batchResponse['items']) && is_array($batchResponse['items'])) {
                                $itemsBefore = count($allDetectionItems);
                                $allDetectionItems = array_merge($allDetectionItems, $batchResponse['items']);
                                $itemsAfter = count($allDetectionItems);
                                
                                Log::info('[PBN Batch] Batch items merged', [
                                    'task_id' => $task->task_id,
                                    'batch_number' => $batchIndex + 1,
                                    'batch_items_count' => count($batchResponse['items']),
                                    'total_items_before' => $itemsBefore,
                                    'total_items_after' => $itemsAfter,
                                ]);
                            }
                            
                            // Collect summary data (merge if multiple batches)
                            if (isset($batchResponse['summary'])) {
                                $allSummaries[] = $batchResponse['summary'];
                                Log::info('[PBN Batch] Batch summary collected', [
                                    'task_id' => $task->task_id,
                                    'batch_number' => $batchIndex + 1,
                                    'summary' => $batchResponse['summary'],
                                ]);
                            }
                        }
                    } catch (PbnDetectorException $e) {
                        Log::warning('[PBN Batch] Batch processing failed - continuing with other batches', [
                            'task_id' => $task->task_id,
                            'batch_task_id' => $batchTaskId,
                            'batch_number' => $batchIndex + 1,
                            'error_message' => $e->getMessage(),
                            'error_code' => $e->getErrorCode(),
                            'http_status' => $e->getStatusCode(),
                        ]);
                        // Continue with next batch instead of failing completely
                        continue;
                    }
                }

                Log::info('[PBN Batch] All batches processed', [
                    'task_id' => $task->task_id,
                    'total_batches' => count($batches),
                    'total_items_collected' => count($allDetectionItems),
                    'total_summaries_collected' => count($allSummaries),
                ]);

                // Combine all batch results
                $detectionResponse = [
                    'items' => $allDetectionItems,
                    'summary' => $this->mergeBatchSummaries($allSummaries),
                ];

                if (empty($allDetectionItems)) {
                    // Empty response - mark as skipped
                    $reflection = new \ReflectionClass($this->repository);
                    $finalizeMethod = $reflection->getMethod('finalizeDetectionRecord');
                    $finalizeMethod->setAccessible(true);
                    $finalizeMethod->invoke($this->repository, $task->task_id, 'skipped');
                    
                    $existingResult = $task->result ?? [];
                    $finalResult = array_merge($existingResult, [
                        'summary' => $summary,
                        'pbn_detection' => ['status' => 'skipped', 'reason' => 'empty_response'],
                    ]);
                    $task->markAsCompleted($finalResult);
                    return 'skipped';
                }

                // Apply detection results to DTOs
                $reflection = new \ReflectionClass($this->repository);
                $applyMethod = $reflection->getMethod('applyDetectionResults');
                $applyMethod->setAccessible(true);
                $applyMethod->invoke($this->repository, $backlinkDtos, $detectionResponse['items'] ?? []);

                // Store updated backlinks with PBN data
                $storeMethod = $reflection->getMethod('storeBacklinkDtos');
                $storeMethod->setAccessible(true);
                DB::transaction(function () use ($storeMethod, $backlinkDtos) {
                    $storeMethod->invoke($this->repository, $backlinkDtos, false);
                });

                // Finalize PBN detection record
                $finalizeMethod = $reflection->getMethod('finalizeDetectionRecord');
                $finalizeMethod->setAccessible(true);
                $finalizeMethod->invoke($this->repository, $task->task_id, 'completed', $detectionResponse);

                Log::info('[Backlink Process] Fetching backlinks from database to update SEO task result', [
                    'task_id' => $task->task_id,
                    'domain' => $task->domain,
                ]);

                // Fetch backlinks from database after processing - ensure fresh data
                // We'll use the DTOs which have the latest PBN data, but also fetch from DB as fallback
                $updatedBacklinkModels = Backlink::where('task_id', $task->task_id)
                    ->orderBy('source_url')
                    ->get();
                
                Log::info('[Backlink Process] Backlinks fetched from database', [
                    'task_id' => $task->task_id,
                    'backlinks_count' => $updatedBacklinkModels->count(),
                ]);

                // Format backlinks for result - use DTOs which have the latest PBN data
                $formattedBacklinks = [];
                foreach ($updatedBacklinkModels as $backlink) {
                    // Find matching DTO to get the latest PBN data
                    $matchingDto = null;
                    $normalizedUrl = rtrim(strtolower($backlink->source_url), '/');
                    foreach ($backlinkDtos as $dto) {
                        $dtoNormalizedUrl = rtrim(strtolower($dto->sourceUrl), '/');
                        if ($dtoNormalizedUrl === $normalizedUrl) {
                            $matchingDto = $dto;
                            break;
                        }
                    }

                    // Use DTO data if available (has latest PBN data), otherwise fall back to model
                    $pbnProbability = $matchingDto?->pbnProbability ?? $backlink->pbn_probability;
                    $riskLevel = $matchingDto?->riskLevel ?? $backlink->risk_level;
                    $pbnReasons = $matchingDto?->pbnReasons ?? (is_string($backlink->pbn_reasons) ? json_decode($backlink->pbn_reasons, true) : $backlink->pbn_reasons);
                    $pbnSignals = $matchingDto?->pbnSignals ?? (is_string($backlink->pbn_signals) ? json_decode($backlink->pbn_signals, true) : $backlink->pbn_signals);

                    Log::debug('[Backlink Process] Formatting backlink', [
                        'task_id' => $task->task_id,
                        'source_url' => $backlink->source_url,
                        'pbn_probability_from_dto' => $matchingDto?->pbnProbability,
                        'pbn_probability_from_db' => $backlink->pbn_probability,
                        'pbn_probability_final' => $pbnProbability,
                        'has_matching_dto' => $matchingDto !== null,
                    ]);

                    $formattedBacklinks[] = [
                        'source_url' => $backlink->source_url,
                        'source_domain' => $backlink->source_domain,
                        'anchor' => $backlink->anchor,
                        'link_type' => $backlink->link_type,
                        'domain_rank' => $backlink->domain_rank,
                        'ip' => $backlink->ip,
                        'asn' => $backlink->asn,
                        'hosting_provider' => $backlink->hosting_provider,
                        'whois_registrar' => $backlink->whois_registrar,
                        'domain_age_days' => $backlink->domain_age_days,
                        'content_fingerprint' => $backlink->content_fingerprint,
                        'pbn_probability' => $pbnProbability !== null ? (float)$pbnProbability : null,
                        'risk_level' => $riskLevel,
                        'pbn_reasons' => $pbnReasons,
                        'pbn_signals' => $pbnSignals,
                        'safe_browsing_status' => $backlink->safe_browsing_status,
                        'safe_browsing_threats' => is_string($backlink->safe_browsing_threats) ? json_decode($backlink->safe_browsing_threats, true) : $backlink->safe_browsing_threats,
                        'backlink_spam_score' => $backlink->backlink_spam_score,
                        'first_seen' => $backlink->first_seen?->toIso8601String(),
                        'last_seen' => $backlink->last_seen?->toIso8601String(),
                        'dofollow' => $backlink->link_type === 'dofollow',
                        'links_count' => $backlink->links_count ?? 1,
                    ];
                }

                Log::info('[Backlink Process] Backlinks formatted for result', [
                    'task_id' => $task->task_id,
                    'formatted_count' => count($formattedBacklinks),
                ]);

                // Prepare final result for SeoTask with backlinks from database
                $existingResult = $task->result ?? [];
                $backlinksData = [
                    'items' => $formattedBacklinks,
                    'total' => count($formattedBacklinks),
                ];

                // Mark task as completed with full results including backlinks from database
                $finalResult = array_merge($existingResult, [
                    'backlinks' => $backlinksData,
                    'summary' => $summary,
                    'pbn_detection' => $detectionResponse,
                ]);

                Log::info('[Backlink Process] Updating SEO task with complete results', [
                    'task_id' => $task->task_id,
                    'backlinks_count' => count($formattedBacklinks),
                    'has_summary' => !empty($summary),
                    'has_pbn_detection' => !empty($detectionResponse),
                ]);

                $task->markAsCompleted($finalResult);
                
                // Refresh the task to ensure we have the latest data
                $task->refresh();
                
                // Clear any potential cache for this task
                $cacheKeys = [
                    "seo_task:{$task->task_id}",
                    "seo_task:{$task->domain}:{$task->task_id}",
                    "backlinks:{$task->task_id}",
                    "backlinks:{$task->domain}",
                ];
                
                foreach ($cacheKeys as $cacheKey) {
                    Cache::forget($cacheKey);
                }

                // Verify the data was stored correctly
                $verificationBacklink = Backlink::where('task_id', $task->task_id)
                    ->whereNotNull('pbn_probability')
                    ->first();
                
                $pbnProbabilitiesInResult = array_filter(
                    array_column($formattedBacklinks, 'pbn_probability'),
                    fn($val) => $val !== null && $val > 0
                );

                Log::info('[Backlink Process] SEO task updated successfully', [
                    'task_id' => $task->task_id,
                    'status' => $task->status,
                    'result_has_backlinks' => isset($task->result['backlinks']),
                    'backlinks_count_in_result' => count($task->result['backlinks']['items'] ?? []),
                    'backlinks_with_pbn_data' => count($pbnProbabilitiesInResult),
                    'sample_pbn_probability' => $task->result['backlinks']['items'][0]['pbn_probability'] ?? null,
                    'verification_backlink_pbn_probability' => $verificationBacklink?->pbn_probability,
                    'cache_cleared' => true,
                ]);

                try {
                    Log::info('PBN detection completed synchronously', [
                        'task_id' => $task->task_id,
                        'domain' => $task->domain,
                        'backlinks_processed' => count($backlinkDtos),
                        'pbn_summary' => $detectionResponse['summary'] ?? 'N/A',
                    ]);
                } catch (\Exception $logError) {
                    // Silently ignore logging errors
                }

                return 'success';
            } catch (PbnDetectorException $e) {
                try {
                    Log::error('PBN detection failed', [
                        'task_id' => $task->task_id,
                        'domain' => $task->domain,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Exception $logError) {
                    // Silently ignore logging errors
                }

                // Finalize detection record
                $reflection = new \ReflectionClass($this->repository);
                $finalizeMethod = $reflection->getMethod('finalizeDetectionRecord');
                $finalizeMethod->setAccessible(true);
                $finalizeMethod->invoke($this->repository, $task->task_id, 'failed', null, $e->getMessage());

                // Fetch backlinks from database even on failure
                $backlinkModels = Backlink::where('task_id', $task->task_id)->get();
                $formattedBacklinks = [];
                foreach ($backlinkModels as $backlink) {
                    $formattedBacklinks[] = [
                        'source_url' => $backlink->source_url,
                        'source_domain' => $backlink->source_domain,
                        'anchor' => $backlink->anchor,
                        'link_type' => $backlink->link_type,
                        'domain_rank' => $backlink->domain_rank,
                        'ip' => $backlink->ip,
                        'asn' => $backlink->asn,
                        'hosting_provider' => $backlink->hosting_provider,
                        'whois_registrar' => $backlink->whois_registrar,
                        'domain_age_days' => $backlink->domain_age_days,
                        'content_fingerprint' => $backlink->content_fingerprint,
                        'pbn_probability' => $backlink->pbn_probability,
                        'risk_level' => $backlink->risk_level,
                        'pbn_reasons' => is_string($backlink->pbn_reasons) ? json_decode($backlink->pbn_reasons, true) : $backlink->pbn_reasons,
                        'pbn_signals' => is_string($backlink->pbn_signals) ? json_decode($backlink->pbn_signals, true) : $backlink->pbn_signals,
                        'safe_browsing_status' => $backlink->safe_browsing_status,
                        'safe_browsing_threats' => is_string($backlink->safe_browsing_threats) ? json_decode($backlink->safe_browsing_threats, true) : $backlink->safe_browsing_threats,
                        'backlink_spam_score' => $backlink->backlink_spam_score,
                        'first_seen' => $backlink->first_seen?->toIso8601String(),
                        'last_seen' => $backlink->last_seen?->toIso8601String(),
                        'dofollow' => $backlink->link_type === 'dofollow',
                        'links_count' => $backlink->links_count ?? 1,
                    ];
                }

                // Mark task as completed with error but include backlinks
                $existingResult = $task->result ?? [];
                $finalResult = array_merge($existingResult, [
                    'backlinks' => [
                        'items' => $formattedBacklinks,
                        'total' => count($formattedBacklinks),
                    ],
                    'summary' => $summary,
                    'pbn_detection' => ['status' => 'failed', 'error' => $e->getMessage()],
                ]);
                $task->markAsCompleted($finalResult);
                
                return 'failed';
            } catch (\Exception $e) {
                try {
                    Log::error('Unexpected error in PBN detection', [
                        'task_id' => $task->task_id,
                        'domain' => $task->domain,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                } catch (\Exception $logError) {
                    // Silently ignore logging errors
                }

                // Finalize detection record
                $reflection = new \ReflectionClass($this->repository);
                $finalizeMethod = $reflection->getMethod('finalizeDetectionRecord');
                $finalizeMethod->setAccessible(true);
                $finalizeMethod->invoke($this->repository, $task->task_id, 'failed', null, $e->getMessage());

                // Fetch backlinks from database before marking as failed
                try {
                    $backlinkModels = Backlink::where('task_id', $task->task_id)->get();
                    $formattedBacklinks = [];
                    foreach ($backlinkModels as $backlink) {
                        $formattedBacklinks[] = [
                            'source_url' => $backlink->source_url,
                            'source_domain' => $backlink->source_domain,
                            'anchor' => $backlink->anchor,
                            'link_type' => $backlink->link_type,
                            'domain_rank' => $backlink->domain_rank,
                            'ip' => $backlink->ip,
                            'asn' => $backlink->asn,
                            'hosting_provider' => $backlink->hosting_provider,
                            'whois_registrar' => $backlink->whois_registrar,
                            'domain_age_days' => $backlink->domain_age_days,
                            'content_fingerprint' => $backlink->content_fingerprint,
                            'pbn_probability' => $backlink->pbn_probability,
                            'risk_level' => $backlink->risk_level,
                            'pbn_reasons' => is_string($backlink->pbn_reasons) ? json_decode($backlink->pbn_reasons, true) : $backlink->pbn_reasons,
                            'pbn_signals' => is_string($backlink->pbn_signals) ? json_decode($backlink->pbn_signals, true) : $backlink->pbn_signals,
                            'safe_browsing_status' => $backlink->safe_browsing_status,
                            'safe_browsing_threats' => is_string($backlink->safe_browsing_threats) ? json_decode($backlink->safe_browsing_threats, true) : $backlink->safe_browsing_threats,
                            'backlink_spam_score' => $backlink->backlink_spam_score,
                            'first_seen' => $backlink->first_seen?->toIso8601String(),
                            'last_seen' => $backlink->last_seen?->toIso8601String(),
                            'dofollow' => $backlink->link_type === 'dofollow',
                            'links_count' => $backlink->links_count ?? 1,
                        ];
                    }

                    // Update result with backlinks before marking as failed
                    $existingResult = $task->result ?? [];
                    $task->update([
                        'result' => array_merge($existingResult, [
                            'backlinks' => [
                                'items' => $formattedBacklinks,
                                'total' => count($formattedBacklinks),
                            ],
                            'summary' => $summary ?? [],
                            'pbn_detection' => ['status' => 'failed', 'error' => $e->getMessage()],
                        ]),
                    ]);
                } catch (\Exception $updateError) {
                    // If updating result fails, just log and continue
                    try {
                        Log::warning('[Backlink Process] Failed to update result with backlinks before marking as failed', [
                            'task_id' => $task->task_id,
                            'error' => $updateError->getMessage(),
                        ]);
                    } catch (\Exception $logError) {
                        // Silently ignore
                    }
                }

                // Mark task as failed
                $task->markAsFailed($e->getMessage());
                
                throw $e;
            }
    }

    /**
     * Merge multiple batch summaries into a single summary
     */
    protected function mergeBatchSummaries(array $summaries): array
    {
        if (empty($summaries)) {
            return [];
        }

        if (count($summaries) === 1) {
            return $summaries[0];
        }

        $merged = [
            'high_risk_count' => 0,
            'medium_risk_count' => 0,
            'low_risk_count' => 0,
            'total_analyzed' => 0,
        ];

        foreach ($summaries as $summary) {
            if (is_array($summary)) {
                $merged['high_risk_count'] += (int) ($summary['high_risk_count'] ?? 0);
                $merged['medium_risk_count'] += (int) ($summary['medium_risk_count'] ?? 0);
                $merged['low_risk_count'] += (int) ($summary['low_risk_count'] ?? 0);
                $merged['total_analyzed'] += (int) ($summary['total_analyzed'] ?? 0);
            }
        }

        return $merged;
    }
}
