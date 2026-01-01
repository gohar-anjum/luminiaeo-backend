<?php

namespace App\Jobs;

use App\DTOs\CitationQueryResultDTO;
use App\Interfaces\CitationRepositoryInterface;
use App\Models\CitationTask;
use App\Services\CitationService;
use App\Services\LLM\LLMClient;
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
        CitationService $service,
        LLMClient $llmClient
    ): void {
        $task = $repository->find($this->taskId);

        if (!$task) {
            Log::warning('[Citation Chunk] Job skipped; task missing', ['task_id' => $this->taskId]);
            return;
        }

        if ($task->status === CitationTask::STATUS_FAILED) {
            Log::info('[Citation Chunk] Job skipped; task already failed', ['task_id' => $task->id]);
            return;
        }

        Log::info('[Citation Chunk] Starting citation validation with LLM providers', [
            'task_id' => $task->id,
            'domain' => $task->url,
            'chunk_size' => count($this->chunk),
            'offset' => $this->offset,
            'total_queries' => $this->totalQueries,
        ]);

        $results = [];

        try {
            // Call GPT (OpenAI) for citation validation
            Log::info('[Citation Chunk] Calling GPT (OpenAI) for citation validation', [
                'task_id' => $task->id,
                'chunk_size' => count($this->chunk),
            ]);

            $gptStartTime = microtime(true);
            $gptResults = $llmClient->batchValidateCitations($this->chunk, $task->url, 'openai');
            $gptDuration = round((microtime(true) - $gptStartTime) * 1000, 2);

            Log::info('[Citation Chunk] GPT validation completed', [
                'task_id' => $task->id,
                'duration_ms' => $gptDuration,
                'results_count' => count($gptResults),
            ]);

            // Call Gemini for citation validation
            Log::info('[Citation Chunk] Calling Gemini for citation validation', [
                'task_id' => $task->id,
                'chunk_size' => count($this->chunk),
            ]);

            $geminiStartTime = microtime(true);
            $geminiResults = $llmClient->batchValidateCitations($this->chunk, $task->url, 'gemini');
            $geminiDuration = round((microtime(true) - $geminiStartTime) * 1000, 2);

            Log::info('[Citation Chunk] Gemini validation completed', [
                'task_id' => $task->id,
                'duration_ms' => $geminiDuration,
                'results_count' => count($geminiResults),
            ]);

            // Process results for each query
            foreach ($this->chunk as $index => $query) {
                $gptResult = $gptResults[$index] ?? $this->defaultResult('gpt', $query);
                $geminiResult = $geminiResults[$index] ?? $this->defaultResult('gemini', $query);

                // Convert LLM results to expected format
                $gpt = [
                    'provider' => 'gpt',
                    'citation_found' => $gptResult['citation_found'] ?? false,
                    'confidence' => $gptResult['confidence'] ?? 0.0,
                    'citation_references' => $gptResult['citation_references'] ?? [],
                    'competitors' => $gptResult['competitors'] ?? [],
                    'explanation' => $gptResult['explanation'] ?? 'No explanation provided',
                    'raw_response' => $gptResult['raw_response'] ?? null,
                    'query' => $query,
                ];

                $gemini = [
                    'provider' => 'gemini',
                    'citation_found' => $geminiResult['citation_found'] ?? false,
                    'confidence' => $geminiResult['confidence'] ?? 0.0,
                    'citation_references' => $geminiResult['citation_references'] ?? [],
                    'competitors' => $geminiResult['competitors'] ?? [],
                    'explanation' => $geminiResult['explanation'] ?? 'No explanation provided',
                    'raw_response' => $geminiResult['raw_response'] ?? null,
                    'query' => $query,
                ];

                // Merge competitors from both providers
                $topCompetitors = $this->mergeCompetitors(
                    $task->url,
                    $gpt['competitors'] ?? [],
                    $gemini['competitors'] ?? []
                );

                Log::debug('[Citation Chunk] Processed query result', [
                    'task_id' => $task->id,
                    'query_index' => $index,
                    'query' => $query,
                    'gpt_citation_found' => $gpt['citation_found'],
                    'gemini_citation_found' => $gemini['citation_found'],
                    'gpt_confidence' => $gpt['confidence'],
                    'gemini_confidence' => $gemini['confidence'],
                ]);

                $dto = new CitationQueryResultDTO((int) $index, $query, $gpt, $gemini, $topCompetitors);
                $results[(string) $index] = $dto->toArray();
            }

            Log::info('[Citation Chunk] All queries processed successfully', [
                'task_id' => $task->id,
                'total_results' => count($results),
                'gpt_duration_ms' => $gptDuration,
                'gemini_duration_ms' => $geminiDuration,
            ]);

        } catch (\Exception $e) {
            Log::error('[Citation Chunk] Citation validation failed', [
                'task_id' => $task->id,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
            ]);

            // Fallback: return empty results for failed chunk
            foreach ($this->chunk as $index => $query) {
                $gpt = $this->defaultResult('gpt', $query);
                $gemini = $this->defaultResult('gemini', $query);
                $topCompetitors = [];

                $dto = new CitationQueryResultDTO((int) $index, $query, $gpt, $gemini, $topCompetitors);
                $results[(string) $index] = $dto->toArray();
            }
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
                // Handle LLM format
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
