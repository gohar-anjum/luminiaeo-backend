<?php

namespace App\Console\Commands;

use App\DTOs\BacklinkDTO;
use App\Exceptions\PbnDetectorException;
use App\Jobs\ProcessPbnDetectionJob;
use App\Models\Backlink;
use App\Models\PbnDetection;
use App\Models\SeoTask;
use App\Repositories\DataForSEO\BacklinksRepository;
use App\Services\DataForSEO\BacklinksService;
use App\Services\Pbn\PbnDetectorService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
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
                            {--max-age=24 : Maximum age in hours for pending/processing tasks}
                            {--async : Dispatch job asynchronously instead of processing synchronously}';

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
        $async = $this->option('async');

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
                $result = $this->processTask($task, $skipEnrichment, $maxAgeHours, $async);
                
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

    protected function processTask(SeoTask $task, bool $skipEnrichment, int $maxAgeHours = 24, bool $async = false): string
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

        // Process synchronously or dispatch async job
        if ($async) {
            // Dispatch job asynchronously (original behavior)
            try {
                ProcessPbnDetectionJob::dispatch(
                    $task->task_id,
                    $task->domain,
                    $detectionPayload,
                    $summary
                );

                $task->markAsProcessing();
                $existingResult = $task->result ?? [];
                $task->update([
                    'result' => array_merge($existingResult, [
                        'summary' => $summary,
                        'pbn_detection' => ['status' => 'processing', 'reprocessed_at' => now()->toIso8601String()],
                    ]),
                ]);

                try {
                    Log::info('Re-dispatched PBN detection job for stuck task', [
                        'task_id' => $task->task_id,
                        'domain' => $task->domain,
                        'backlinks_count' => count($backlinkDtos),
                    ]);
                } catch (\Exception $logError) {
                    // Silently ignore logging errors
                }

                return 'success';
            } catch (\Exception $e) {
                try {
                    Log::error('Failed to dispatch PBN detection job', [
                        'task_id' => $task->task_id,
                        'domain' => $task->domain,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Exception $logError) {
                    // Silently ignore logging errors
                }
                throw $e;
            }
        } else {
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
                
                try {
                    Log::info('Processing PBN detection in batches', [
                        'task_id' => $task->task_id,
                        'domain' => $task->domain,
                        'total_backlinks' => count($detectionPayload),
                        'batch_size' => $maxBacklinksPerRequest,
                        'number_of_batches' => count($batches),
                    ]);
                } catch (\Exception $logError) {
                    // Silently ignore logging errors
                }

                foreach ($batches as $batchIndex => $batch) {
                    try {
                        try {
                            Log::info('Processing PBN detection batch', [
                                'task_id' => $task->task_id,
                                'batch' => $batchIndex + 1,
                                'total_batches' => count($batches),
                                'batch_size' => count($batch),
                            ]);
                        } catch (\Exception $logError) {
                            // Silently ignore logging errors
                        }

                        // Call PBN microservice for this batch
                        $batchResponse = $this->pbnDetector->analyze(
                            $task->domain,
                            $task->task_id . '_batch_' . ($batchIndex + 1),
                            $batch,
                            $summary
                        );

                        if (!empty($batchResponse)) {
                            // Collect items from this batch
                            if (isset($batchResponse['items']) && is_array($batchResponse['items'])) {
                                $allDetectionItems = array_merge($allDetectionItems, $batchResponse['items']);
                            }
                            
                            // Collect summary data (merge if multiple batches)
                            if (isset($batchResponse['summary'])) {
                                $allSummaries[] = $batchResponse['summary'];
                            }
                        }
                    } catch (PbnDetectorException $e) {
                        try {
                            Log::warning('PBN detection batch failed, continuing with other batches', [
                                'task_id' => $task->task_id,
                                'batch' => $batchIndex + 1,
                                'error' => $e->getMessage(),
                            ]);
                        } catch (\Exception $logError) {
                            // Silently ignore logging errors
                        }
                        // Continue with next batch instead of failing completely
                        continue;
                    }
                }

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

                // Prepare final result for SeoTask
                $existingResult = $task->result ?? [];
                $existingBacklinksData = $existingResult['backlinks'] ?? [];
                
                // Ensure 'items' key exists
                if (!isset($existingBacklinksData['items']) || !is_array($existingBacklinksData['items'])) {
                    $existingBacklinksData['items'] = [];
                }

                // Create lookup map for enriched backlinks
                $enrichedLookup = [];
                foreach ($backlinkDtos as $dto) {
                    $normalizedUrl = rtrim(strtolower($dto->sourceUrl), '/');
                    $enrichedLookup[$normalizedUrl] = $dto->toArray();
                }

                // Merge PBN data into original items structure
                $mergedItems = [];
                foreach ($existingBacklinksData['items'] as $originalItem) {
                    $sourceUrl = $originalItem['source_url'] ?? $originalItem['url_from'] ?? $originalItem['url_to'] ?? null;
                    if ($sourceUrl) {
                        $normalizedItemUrl = rtrim(strtolower($sourceUrl), '/');
                        $enrichedData = $enrichedLookup[$normalizedItemUrl] ?? null;
                        if ($enrichedData) {
                            // Merge PBN and enrichment fields
                            $originalItem = array_merge($originalItem, Arr::only($enrichedData, [
                                'ip', 'asn', 'hosting_provider', 'whois_registrar', 'domain_age_days',
                                'content_fingerprint', 'pbn_probability', 'risk_level', 'pbn_reasons',
                                'pbn_signals', 'safe_browsing_status', 'safe_browsing_threats',
                                'safe_browsing_checked_at', 'backlink_spam_score',
                            ]));
                        }
                    }
                    $mergedItems[] = $originalItem;
                }
                $existingBacklinksData['items'] = $mergedItems;

                // Mark task as completed with full results
                $finalResult = array_merge($existingResult, [
                    'backlinks' => $existingBacklinksData,
                    'summary' => $summary,
                    'pbn_detection' => $detectionResponse,
                ]);

                $task->markAsCompleted($finalResult);

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

                // Mark task as completed with error
                $existingResult = $task->result ?? [];
                $finalResult = array_merge($existingResult, [
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

                // Mark task as failed
                $task->markAsFailed($e->getMessage());
                
                throw $e;
            }
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
