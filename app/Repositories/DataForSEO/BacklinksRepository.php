<?php

namespace App\Repositories\DataForSEO;

                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        use App\DTOs\BacklinkDTO;
use App\Exceptions\DataForSEOException;
use App\Exceptions\PbnDetectorException;
use App\Interfaces\DataForSEO\BacklinksRepositoryInterface;
use App\Models\Backlink;
use App\Models\PbnDetection;
use App\Models\SeoTask;
use App\Services\DataForSEO\BacklinksService;
use App\Services\Pbn\PbnDetectorService;
use App\Services\SafeBrowsing\SafeBrowsingService;
use App\Services\Whois\WhoisLookupService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BacklinksRepository implements BacklinksRepositoryInterface
{
    protected BacklinksService $service;
    protected WhoisLookupService $whoisLookup;
    protected PbnDetectorService $pbnDetector;
    protected SafeBrowsingService $safeBrowsing;
    protected int $backlinksLimit;

    public function __construct(
        BacklinksService $service,
        WhoisLookupService $whoisLookup,
        PbnDetectorService $pbnDetector,
        SafeBrowsingService $safeBrowsing
    ) {
        $this->service = $service;
        $this->whoisLookup = $whoisLookup;
        $this->pbnDetector = $pbnDetector;
        $this->safeBrowsing = $safeBrowsing;
        $this->backlinksLimit = (int) config('services.dataforseo.backlinks_limit', 100);
    }

    /**
     * Create a new backlinks task
     *
     * @param string $domain Domain to analyze
     * @param int $limit Maximum number of backlinks to retrieve
     * @return SeoTask Created task record
     */
    public function createTask(string $domain, int $limit = 100): SeoTask
    {
        $limit = min($limit, $this->backlinksLimit);

        $seoTask = null;
        $backlinks = [];

        try {
            $taskResponse = $this->service->submitBacklinksTask($domain, $limit);
            $taskId = $taskResponse['id'] ?? $taskResponse['task_id'] ?? null;

            if (empty($taskId)) {
                throw new DataForSEOException('Task ID missing from DataForSEO response', 500, 'INVALID_RESPONSE');
            }

            $resultSet = $this->normalizeResultSet($taskResponse, $domain, $taskId);
            $summary = $this->service->getBacklinksSummary($domain, $limit);
            $items = $resultSet['items'] ?? [];

            [$seoTask, $backlinks] = DB::transaction(function () use ($domain, $limit, $resultSet, $items, $taskId) {
                $seoTask = SeoTask::create([
                    'task_id' => $taskId,
                    'type' => SeoTask::TYPE_BACKLINKS,
                    'domain' => $domain,
                    'status' => SeoTask::STATUS_PROCESSING,
                    'payload' => [
                        'domain' => $domain,
                        'limit' => $limit,
                    ],
                    'submitted_at' => now(),
                ]);

                $backlinks = $this->hydrateBacklinks($items, $domain, $seoTask->task_id);
                $this->enrichWithWhois($backlinks);
                $this->enrichWithSafeBrowsing($backlinks);
                $this->storeBacklinkDtos($backlinks, true);

                PbnDetection::updateOrCreate(
                    ['task_id' => $seoTask->task_id],
                    [
                        'domain' => $domain,
                        'status' => 'pending',
                        'analysis_started_at' => now(),
                    ]
                );

                return [$seoTask, $backlinks];
            });

            $detectionPayload = $this->formatDetectionPayload($backlinks);
            $detectionResponse = $this->runPbnDetection(
                $domain,
                $taskId,
                $detectionPayload,
                $summary
            );

            if (!empty($detectionResponse)) {
                $this->applyDetectionResults($backlinks, $detectionResponse['items'] ?? []);
                $this->storeBacklinkDtos($backlinks, false);
                $this->finalizeDetectionRecord($seoTask->task_id, 'completed', $detectionResponse);

                $seoTask->markAsCompleted([
                    'backlinks' => $resultSet,
                    'summary' => $summary,
                    'pbn_detection' => $detectionResponse,
                ]);
            } else {
                $this->finalizeDetectionRecord($seoTask->task_id, 'skipped');
                $seoTask->markAsCompleted([
                'backlinks' => $resultSet,
                    'summary' => $summary,
                    'pbn_detection' => ['status' => 'skipped'],
                ]);
            }

            return $seoTask->fresh();
        } catch (PbnDetectorException $e) {
            Log::error('PBN detection failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            $this->finalizeDetectionRecord($seoTask->task_id ?? null, 'failed', null, $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to create backlinks task', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            $this->finalizeDetectionRecord($seoTask->task_id ?? null, 'failed', null, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fetch backlinks results for a task
     *
     * @param string $taskId Task ID
     * @return array Backlinks results or pending status
     */
    public function fetchResults(string $taskId): array
    {
        try {
            $seoTask = $this->getTaskStatus($taskId);

            if (!$seoTask) {
                throw new DataForSEOException(
                    'Backlink task not found',
                    404,
                    'TASK_NOT_FOUND'
                );
            }

            return $seoTask->result ?? [];
        } catch (\Exception $e) {
            Log::error('Failed to fetch backlinks results', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get task status from database
     *
     * @param string $taskId Task ID
     * @return SeoTask|null Task record or null if not found
     */
    public function getTaskStatus(string $taskId): ?SeoTask
    {
        return SeoTask::where('task_id', $taskId)->first();
    }

    /**
     * Update task status
     *
     * @param string $taskId Task ID
     * @param string $status New status
     * @param array|null $result Result data
     * @param string|null $errorMessage Error message if failed
     * @return bool Success status
     */
    public function updateTaskStatus(string $taskId, string $status, array $result = null, string $errorMessage = null): bool
    {
        $seoTask = SeoTask::where('task_id', $taskId)->first();

        if (!$seoTask) {
            Log::warning('Task not found for status update', [
                'task_id' => $taskId,
                'status' => $status,
            ]);
            return false;
        }

        switch ($status) {
            case SeoTask::STATUS_PROCESSING:
                $seoTask->markAsProcessing();
                break;
            case SeoTask::STATUS_COMPLETED:
                $seoTask->markAsCompleted($result);
                break;
            case SeoTask::STATUS_FAILED:
                $seoTask->markAsFailed($errorMessage ?? 'Unknown error');
                break;
            default:
                $seoTask->update(['status' => $status]);
        }

        Log::info('Updated task status', [
            'task_id' => $taskId,
            'status' => $status,
        ]);

        return true;
    }

    public function getHarmfulBacklinks(string $domain, array $riskLevels = ['high', 'critical']): array
    {
        return Backlink::query()
            ->where('domain', $domain)
            ->whereIn('risk_level', $riskLevels)
            ->orderByDesc('pbn_probability')
            ->get()
            ->toArray();
    }

    /**
     * Persist backlinks returned from DataForSEO.
     */
    protected function storeBacklinkDtos(array $backlinks, bool $isInsert = false): void
    {
        $payload = [];

        foreach ($backlinks as $dto) {
            if (empty($dto->sourceUrl)) {
                continue;
            }

            $entry = $dto->toDatabaseArray();
            
            // Ensure JSON columns are properly encoded
            if (isset($entry['pbn_reasons']) && is_array($entry['pbn_reasons'])) {
                $entry['pbn_reasons'] = json_encode($entry['pbn_reasons'], JSON_UNESCAPED_UNICODE);
            } else {
                $entry['pbn_reasons'] = null;
            }
            
            if (isset($entry['pbn_signals']) && is_array($entry['pbn_signals'])) {
                $entry['pbn_signals'] = json_encode($entry['pbn_signals'], JSON_UNESCAPED_UNICODE);
            } else {
                $entry['pbn_signals'] = null;
            }
            
            if (isset($entry['safe_browsing_threats']) && is_array($entry['safe_browsing_threats'])) {
                $entry['safe_browsing_threats'] = json_encode($entry['safe_browsing_threats'], JSON_UNESCAPED_UNICODE);
            } else {
                $entry['safe_browsing_threats'] = null;
            }
            
            // Ensure all string fields are actually strings, not arrays
            $stringFields = ['asn', 'hosting_provider', 'content_fingerprint', 'ip', 'whois_registrar', 'anchor', 'link_type', 'source_domain', 'source_url', 'domain', 'task_id', 'risk_level', 'safe_browsing_status'];
            foreach ($stringFields as $field) {
                if (isset($entry[$field])) {
                    if (is_array($entry[$field])) {
                        Log::warning("Array detected in string field: {$field}", [
                            'value' => $entry[$field],
                            'source_url' => $entry['source_url'] ?? 'unknown',
                            'field_type' => gettype($entry[$field]),
                        ]);
                        $entry[$field] = null;
                    } elseif (!is_string($entry[$field]) && !is_null($entry[$field])) {
                        $entry[$field] = (string)$entry[$field];
                    }
                }
            }
            
            if ($isInsert) {
                $entry['created_at'] = now();
            }
            $payload[] = $entry;
        }

        if (empty($payload)) {
            Log::warning('No backlink payload to persist');
            return;
        }

        Backlink::upsert(
            $payload,
            ['domain', 'source_url', 'task_id'],
            [
                'anchor',
                'link_type',
                'source_domain',
                'domain_rank',
                'ip',
                'asn',
                'hosting_provider',
                'whois_registrar',
                'domain_age_days',
                'content_fingerprint',
                'pbn_probability',
                'risk_level',
                'pbn_reasons',
                'pbn_signals',
                'safe_browsing_status',
                'safe_browsing_threats',
                'safe_browsing_checked_at',
                'updated_at',
            ]
        );
    }

    protected function hydrateBacklinks(array $items, string $domain, string $taskId): array
    {
        return array_map(function (array $item) use ($domain, $taskId) {
            return BacklinkDTO::fromArray($item, $domain, $taskId);
        }, $items);
    }

    protected function enrichWithWhois(array $backlinks): void
    {
        if (!$this->whoisLookup->enabled()) {
            return;
        }

        $domains = collect($backlinks)
            ->pluck('sourceDomain')
            ->filter()
            ->unique()
            ->values();

        foreach ($domains as $sourceDomain) {
            $whoisRaw = $this->whoisLookup->lookup($sourceDomain);
            $signals = $this->whoisLookup->extractSignals($whoisRaw);

            foreach ($backlinks as $dto) {
                if ($dto->sourceDomain === $sourceDomain) {
                    $dto->applyWhoisSignals($signals);
                }
            }
        }
    }

    protected function enrichWithSafeBrowsing(array $backlinks): void
    {
        if (!$this->safeBrowsing->enabled()) {
            return;
        }

        foreach ($backlinks as $dto) {
            if (empty($dto->sourceUrl)) {
                continue;
            }

            $raw = $this->safeBrowsing->checkUrl($dto->sourceUrl);
            $signals = $this->safeBrowsing->extractSignals($raw);
            $dto->applySafeBrowsing($signals);
        }
    }

    protected function formatDetectionPayload(array $backlinks): array
    {
        return array_map(function (BacklinkDTO $dto) {
            // Convert datetime strings to ISO 8601 format for Pydantic
            $firstSeen = null;
            if ($dto->firstSeen) {
                try {
                    $firstSeen = \Carbon\Carbon::parse($dto->firstSeen)->toIso8601String();
                } catch (\Exception $e) {
                    $firstSeen = null;
                }
            }
            
            $lastSeen = null;
            if ($dto->lastSeen) {
                try {
                    $lastSeen = \Carbon\Carbon::parse($dto->lastSeen)->toIso8601String();
                } catch (\Exception $e) {
                    $lastSeen = null;
                }
            }
            
            $safeBrowsingCheckedAt = null;
            if ($dto->safeBrowsingCheckedAt) {
                try {
                    $safeBrowsingCheckedAt = \Carbon\Carbon::parse($dto->safeBrowsingCheckedAt)->toIso8601String();
                } catch (\Exception $e) {
                    $safeBrowsingCheckedAt = null;
                }
            }
            
            return [
                'source_url' => (string)$dto->sourceUrl,
                'domain_from' => $dto->sourceDomain ? (string)$dto->sourceDomain : null,
                'anchor' => $dto->anchor ? (string)$dto->anchor : null,
                'link_type' => $dto->linkType ? (string)$dto->linkType : null,
                'domain_rank' => $dto->domainRank,
                'ip' => $dto->ip ? (string)$dto->ip : null,
                'whois_registrar' => $dto->whoisRegistrar ? (string)$dto->whoisRegistrar : null,
                'domain_age_days' => $dto->domainAgeDays,
                'first_seen' => $firstSeen,
                'last_seen' => $lastSeen,
                'dofollow' => $dto->isDofollow ?? false,
                'links_count' => $dto->linksCount,
                'safe_browsing_status' => $dto->safeBrowsingStatus ?? 'unknown',
                'safe_browsing_threats' => is_array($dto->safeBrowsingThreats) ? $dto->safeBrowsingThreats : [],
                'safe_browsing_checked_at' => $safeBrowsingCheckedAt,
                'raw' => is_array($dto->raw) ? $dto->raw : [],
            ];
        }, $backlinks);
    }

    protected function runPbnDetection(string $domain, string $taskId, array $backlinks, array $summary): array
    {
        if (!$this->pbnDetector->enabled()) {
            Log::warning('PBN detection skipped: service not configured');
            return [];
        }

        return $this->pbnDetector->analyze($domain, $taskId, $backlinks, $summary);
    }

    protected function applyDetectionResults(array $backlinks, array $results): void
    {
        if (empty($results)) {
            return;
        }

        $indexed = [];
        foreach ($results as $result) {
            if (!empty($result['source_url'])) {
                $indexed[$result['source_url']] = $result;
            }
        }

        foreach ($backlinks as $dto) {
            if (isset($indexed[$dto->sourceUrl])) {
                $dto->applyDetection($indexed[$dto->sourceUrl]);
            }
        }
    }

    protected function finalizeDetectionRecord(
        ?string $taskId,
        string $status,
        array $payload = null,
        ?string $errorMessage = null
    ): void {
        if (empty($taskId)) {
            return;
        }

        PbnDetection::where('task_id', $taskId)->update([
            'status' => $status,
            'high_risk_count' => Arr::get($payload, 'summary.high_risk_count', 0),
            'medium_risk_count' => Arr::get($payload, 'summary.medium_risk_count', 0),
            'low_risk_count' => Arr::get($payload, 'summary.low_risk_count', 0),
            'analysis_completed_at' => now(),
            'latency_ms' => Arr::get($payload, 'meta.latency_ms'),
            'summary' => $payload['summary'] ?? null,
            'response_payload' => $payload,
            'updated_at' => now(),
            'status_message' => $errorMessage,
        ]);
    }

    protected function normalizeResultSet(array $taskResponse, string $domain, string $taskId): array
    {
        $resultSet = $taskResponse['result'][0] ?? [];
        if (empty($resultSet)) {
            $resultSet = [
                'target' => $domain,
                'mode' => 'as_is',
                'items' => [],
                'items_count' => 0,
                'total_count' => 0,
            ];
        }

        $resultSet['task_id'] = $taskId;

        return $resultSet;
    }
}
