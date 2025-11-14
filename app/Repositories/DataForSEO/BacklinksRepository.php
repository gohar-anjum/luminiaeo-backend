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

    public function createTask(string $domain, int $limit = 100): SeoTask
    {
        $limit = min($limit, $this->backlinksLimit);
        $normalizedDomain = $this->normalizeDomain($domain);

        $cachedTask = $this->getCachedTask($normalizedDomain, $limit);
        if ($cachedTask) {
            return $cachedTask;
        }

        $seoTask = null;
        $backlinks = [];

        try {
            $taskResponse = $this->service->submitBacklinksTask($normalizedDomain, $limit);
            $taskId = $taskResponse['id'] ?? $taskResponse['task_id'] ?? null;

            if (empty($taskId)) {
                throw new DataForSEOException('Task ID missing from DataForSEO response', 500, 'INVALID_RESPONSE');
            }

            $resultSet = $this->normalizeResultSet($taskResponse, $normalizedDomain, $taskId);
            $summary = $this->service->getBacklinksSummary($normalizedDomain, $limit);
            $items = $resultSet['items'] ?? [];

            [$seoTask, $backlinks] = DB::transaction(function () use ($normalizedDomain, $limit, $resultSet, $items, $taskId) {
                $seoTask = SeoTask::create([
                    'task_id' => $taskId,
                    'type' => SeoTask::TYPE_BACKLINKS,
                    'domain' => $normalizedDomain,
                    'status' => SeoTask::STATUS_PROCESSING,
                    'payload' => [
                        'domain' => $normalizedDomain,
                        'limit' => $limit,
                    ],
                    'submitted_at' => now(),
                ]);

                $backlinks = $this->hydrateBacklinks($items, $normalizedDomain, $seoTask->task_id);
                $this->enrichWithWhois($backlinks);
                $this->enrichWithSafeBrowsing($backlinks);
                $this->storeBacklinkDtos($backlinks, true);

                PbnDetection::updateOrCreate(
                    ['task_id' => $seoTask->task_id],
                    [
                        'domain' => $normalizedDomain,
                        'status' => 'pending',
                        'analysis_started_at' => now(),
                    ]
                );

                return [$seoTask, $backlinks];
            });

            $detectionPayload = $this->formatDetectionPayload($backlinks);
            $detectionResponse = $this->runPbnDetection(
                $normalizedDomain,
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
            Log::error('PBN detection failed', ['domain' => $normalizedDomain, 'error' => $e->getMessage()]);
            $this->finalizeDetectionRecord($seoTask->task_id ?? null, 'failed', null, $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to create backlinks task', ['domain' => $normalizedDomain, 'error' => $e->getMessage()]);
            $this->finalizeDetectionRecord($seoTask->task_id ?? null, 'failed', null, $e->getMessage());
            throw $e;
        }
    }

    protected function getCachedTask(string $domain, int $limit): ?SeoTask
    {
        $cachedTask = SeoTask::where('domain', $domain)
            ->where('type', SeoTask::TYPE_BACKLINKS)
            ->where('status', SeoTask::STATUS_COMPLETED)
            ->where('completed_at', '>=', now()->subHours(24))
            ->whereJsonContains('payload->limit', $limit)
            ->orderByDesc('completed_at')
            ->first();

        return $cachedTask ? $cachedTask->fresh() : null;
    }

    protected function normalizeDomain(string $domain): string
    {
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');
        return !str_starts_with($domain, 'http') ? 'https://' . $domain : $domain;
    }

    public function fetchResults(string $taskId): array
    {
        $seoTask = $this->getTaskStatus($taskId);
        if (!$seoTask) {
            throw new DataForSEOException('Backlink task not found', 404, 'TASK_NOT_FOUND');
        }
        return $seoTask->result ?? [];
    }

    public function getTaskStatus(string $taskId): ?SeoTask
    {
        return SeoTask::where('task_id', $taskId)->first();
    }

    public function updateTaskStatus(string $taskId, string $status, array $result = null, string $errorMessage = null): bool
    {
        $seoTask = SeoTask::where('task_id', $taskId)->first();
        if (!$seoTask) {
            return false;
        }

        match ($status) {
            SeoTask::STATUS_PROCESSING => $seoTask->markAsProcessing(),
            SeoTask::STATUS_COMPLETED => $seoTask->markAsCompleted($result),
            SeoTask::STATUS_FAILED => $seoTask->markAsFailed($errorMessage ?? 'Unknown error'),
            default => $seoTask->update(['status' => $status]),
        };

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

    protected function storeBacklinkDtos(array $backlinks, bool $isInsert = false): void
    {
        $payload = [];
        $stringFields = ['asn', 'hosting_provider', 'content_fingerprint', 'ip', 'whois_registrar', 'anchor', 'link_type', 'source_domain', 'source_url', 'domain', 'task_id', 'risk_level', 'safe_browsing_status'];

        foreach ($backlinks as $dto) {
            if (empty($dto->sourceUrl)) {
                continue;
            }

            $entry = $dto->toDatabaseArray();
            
            $entry['pbn_reasons'] = is_array($entry['pbn_reasons'] ?? null) ? json_encode($entry['pbn_reasons'], JSON_UNESCAPED_UNICODE) : null;
            $entry['pbn_signals'] = is_array($entry['pbn_signals'] ?? null) ? json_encode($entry['pbn_signals'], JSON_UNESCAPED_UNICODE) : null;
            $entry['safe_browsing_threats'] = is_array($entry['safe_browsing_threats'] ?? null) ? json_encode($entry['safe_browsing_threats'], JSON_UNESCAPED_UNICODE) : null;
            
            foreach ($stringFields as $field) {
                if (isset($entry[$field]) && is_array($entry[$field])) {
                    $entry[$field] = null;
                } elseif (isset($entry[$field]) && !is_string($entry[$field]) && !is_null($entry[$field])) {
                    $entry[$field] = (string)$entry[$field];
                }
                if ($field === 'whois_registrar' && isset($entry[$field]) && mb_strlen($entry[$field]) > 255) {
                    $entry[$field] = mb_substr($entry[$field], 0, 255);
                }
                if ($field === 'content_fingerprint' && isset($entry[$field]) && mb_strlen($entry[$field]) > 191) {
                    $entry[$field] = mb_substr($entry[$field], 0, 191);
                }
            }
            
            if ($isInsert) {
                $entry['created_at'] = now();
            }
            $payload[] = $entry;
        }

        if (empty($payload)) {
            return;
        }

        Backlink::upsert($payload, ['domain', 'source_url', 'task_id'], [
            'anchor', 'link_type', 'source_domain', 'domain_rank', 'ip', 'asn', 'hosting_provider',
            'whois_registrar', 'domain_age_days', 'content_fingerprint', 'pbn_probability', 'risk_level',
            'pbn_reasons', 'pbn_signals', 'safe_browsing_status', 'safe_browsing_threats',
            'safe_browsing_checked_at', 'updated_at',
        ]);
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
            $parseDate = fn($date) => $date ? (function() use ($date) {
                try { return \Carbon\Carbon::parse($date)->toIso8601String(); } catch (\Exception $e) { return null; }
            })() : null;

            return [
                'source_url' => (string)$dto->sourceUrl,
                'domain_from' => $dto->sourceDomain ? (string)$dto->sourceDomain : null,
                'anchor' => $dto->anchor ? (string)$dto->anchor : null,
                'link_type' => $dto->linkType ? (string)$dto->linkType : null,
                'domain_rank' => $dto->domainRank,
                'ip' => $dto->ip ? (string)$dto->ip : null,
                'whois_registrar' => $dto->whoisRegistrar ? (string)$dto->whoisRegistrar : null,
                'domain_age_days' => $dto->domainAgeDays,
                'first_seen' => $parseDate($dto->firstSeen),
                'last_seen' => $parseDate($dto->lastSeen),
                'dofollow' => $dto->isDofollow ?? false,
                'links_count' => $dto->linksCount,
                'safe_browsing_status' => $dto->safeBrowsingStatus ?? 'unknown',
                'safe_browsing_threats' => is_array($dto->safeBrowsingThreats) ? $dto->safeBrowsingThreats : [],
                'safe_browsing_checked_at' => $parseDate($dto->safeBrowsingCheckedAt),
                'backlink_spam_score' => $dto->backlinkSpamScore,
                'raw' => is_array($dto->raw) ? $dto->raw : [],
            ];
        }, $backlinks);
    }

    protected function runPbnDetection(string $domain, string $taskId, array $backlinks, array $summary): array
    {
        if (!$this->pbnDetector->enabled()) {
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
        $resultSet = $taskResponse['result'][0] ?? [
            'target' => $domain,
            'mode' => 'as_is',
            'items' => [],
            'items_count' => 0,
            'total_count' => 0,
        ];
        $resultSet['task_id'] = $taskId;
        return $resultSet;
    }
}
