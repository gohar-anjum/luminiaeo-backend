<?php

namespace App\Jobs;

use App\DTOs\CitationQueryResultDTO;
use App\Interfaces\CitationRepositoryInterface;
use App\Models\CitationTask;
use App\Services\CitationService;
use App\Services\DataForSEO\CitationService as DataForSEOCitationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CitationChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;

    public function __construct(
        public int $taskId,
        public array $chunk,
        public int $offset,
        public int $totalQueries
    ) {
        $this->onQueue('citations');
    }

    public function handle(
        CitationRepositoryInterface $repository,
        CitationService $service
    ): void {
        $task = $repository->find($this->taskId);

        if (!$task) {
            Log::warning('Citation chunk job skipped; task missing', ['task_id' => $this->taskId]);
            return;
        }

        if ($task->status === CitationTask::STATUS_FAILED) {
            Log::info('Citation chunk job skipped; task already failed', ['task_id' => $task->id]);
            return;
        }

        // DataForSEO is required - no LLM fallback
        if (!config('citations.dataforseo.enabled', false)) {
            throw new \RuntimeException('DataForSEO citation service is required but not enabled. Please set DATAFORSEO_CITATION_ENABLED=true in your .env file.');
        }

        $results = [];

        try {
            $dataForSEOCitationService = app(DataForSEOCitationService::class);
            $dataForSEOResults = $dataForSEOCitationService->batchFindCitations($this->chunk, $task->url);

            foreach ($this->chunk as $index => $query) {
                $dataForSEOResult = $dataForSEOResults[$query] ?? $this->defaultDataForSEOResult($query);
                
                // Format as dataforseo provider result (stored in 'gpt' field for compatibility)
                $dataforseo = [
                    'provider' => 'dataforseo',
                    'citation_found' => $dataForSEOResult['citation_found'] ?? false,
                    'confidence' => $dataForSEOResult['confidence'] ?? 0.0,
                    'citation_references' => $dataForSEOResult['references'] ?? [],
                    'competitors' => $dataForSEOResult['competitors'] ?? [],
                    'explanation' => $dataForSEOResult['citation_found'] 
                        ? 'Citation found via DataForSEO SERP analysis' 
                        : 'No citation found via DataForSEO SERP analysis',
                    'raw_response' => null,
                    'query' => $query,
                ];
                
                // Empty result for gemini (not used when DataForSEO is enabled)
                $gemini = $this->defaultResult('gemini', $query);

                $topCompetitors = $this->mergeCompetitors(
                    $task->url,
                    $dataforseo['competitors'] ?? [],
                    []
                );

                $dto = new CitationQueryResultDTO((int) $index, $query, $dataforseo, $gemini, $topCompetitors);
                $results[(string) $index] = $dto->toArray();
            }
        } catch (\Exception $e) {
            Log::error('DataForSEO citation check failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException('DataForSEO citation check failed: ' . $e->getMessage());
        }

        $meta = [
            'last_chunk_finished_at' => now()->toIso8601String(),
        ];

        $lastIndex = (int) array_key_last($results);
        $progress = [
            'processed_increment' => count($results),
            'last_query_index' => $lastIndex,
            'total' => $this->totalQueries,
        ];

        $updatedTask = $service->mergeChunkResults($task, $results, $meta, $progress);

        if (count($updatedTask->results['by_query'] ?? []) >= $this->totalQueries) {
            $service->finalizeTask($updatedTask);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Citation chunk job failed', [
            'task_id' => $this->taskId,
            'message' => $exception->getMessage(),
        ]);
    }

    protected function mergeCompetitors(string $targetUrl, array ...$lists): array
    {
        $targetDomain = $this->normalizeDomain($targetUrl);
        $tallies = [];

        foreach ($lists as $list) {
            foreach ($list as $entry) {
                // Handle both LLM format and DataForSEO format
                $domain = $this->normalizeDomain($entry['domain'] ?? ($entry['url'] ?? null));
                if (!$domain || $this->isTargetDomain($domain, $targetDomain)) {
                    continue;
                }

                $key = $this->rootDomain($domain);

                if (!isset($tallies[$key])) {
                    $tallies[$key] = [
                        'domain' => $key,
                        'urls' => [],
                        'mentions' => 0,
                    ];
                }

                $tallies[$key]['mentions']++;

                $url = $entry['url'] ?? null;
                if (empty($url) && isset($entry['domain'])) {
                    $url = 'https://' . $entry['domain'];
                }
                if (!empty($url)) {
                    $tallies[$key]['urls'][] = $url;
                }
            }
        }

        foreach ($tallies as &$data) {
            $data['urls'] = array_values(array_unique($data['urls']));
        }
        unset($data);

        usort($tallies, fn ($a, $b) => $b['mentions'] <=> $a['mentions']);

        return array_slice(array_map(function ($item) {
            return [
                'domain' => $item['domain'],
                'urls' => array_slice($item['urls'], 0, 3),
                'mentions' => $item['mentions'],
            ];
        }, $tallies), 0, 2);
    }

    protected function defaultResult(string $provider, string $query): array
    {
        return [
            'provider' => $provider,
            'citation_found' => false,
            'confidence' => 0.0,
            'citation_references' => [],
            'competitors' => [],
            'explanation' => 'Provider result missing',
            'raw_response' => null,
            'query' => $query,
        ];
    }

    protected function defaultDataForSEOResult(string $query): array
    {
        return [
            'citation_found' => false,
            'confidence' => 0.0,
            'references' => [],
            'competitors' => [],
        ];
    }

    protected function normalizeDomain(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST) ?: $value;
        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host);

        return $host ?: null;
    }

    protected function rootDomain(?string $domain): ?string
    {
        if (!$domain) {
            return null;
        }

        $parts = explode('.', $domain);
        if (count($parts) <= 2) {
            return $domain;
        }

        return implode('.', array_slice($parts, -2));
    }

    protected function isTargetDomain(?string $domain, ?string $target): bool
    {
        if (!$domain || !$target) {
            return false;
        }

        return $this->rootDomain($domain) === $this->rootDomain($target);
    }
}
