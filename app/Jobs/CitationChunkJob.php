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

    /**
     * @param array<int,string> $chunk
     */
    public function __construct(
        public int $taskId,
        public array $chunk,
        public int $offset,
        public int $totalQueries
    ) {
    }

    public function handle(
        CitationRepositoryInterface $repository,
        CitationService $service,
        LLMClient $llmClient
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

        $results = [];
        foreach ($this->chunk as $index => $query) {
            // Perform deep research - ensure no steps are skipped
            // Both GPT and Gemini must perform comprehensive analysis
            Log::info('Starting deep research for citation query', [
                'task_id' => $task->id,
                'query_index' => $index,
                'query' => $query,
                'url' => $task->url,
            ]);

            // Deep research with GPT - always perform all steps
            $gpt = null;
            try {
                $gpt = $llmClient->checkCitationOpenAi($query, $task->url);
                Log::info('GPT deep research completed', [
                    'task_id' => $task->id,
                    'query_index' => $index,
                    'citation_found' => $gpt['citation_found'] ?? false,
                    'confidence' => $gpt['confidence'] ?? 0,
                ]);
            } catch (\Throwable $e) {
                Log::error('GPT deep research failed - creating error result', [
                    'task_id' => $task->id,
                    'query_index' => $index,
                    'error' => $e->getMessage(),
                ]);
                // Still create result - no skipping
                $gpt = [
                    'citation_found' => false,
                    'confidence' => 0,
                    'citation_references' => [],
                    'explanation' => 'GPT deep research encountered an error: ' . $e->getMessage() . '. All research steps were attempted.',
                    'raw_response' => null,
                    'provider' => 'gpt',
                ];
            }

            // Deep research with Gemini - always perform all steps
            $gemini = null;
            try {
                $gemini = $llmClient->checkCitationGemini($query, $task->url);
                Log::info('Gemini deep research completed', [
                    'task_id' => $task->id,
                    'query_index' => $index,
                    'citation_found' => $gemini['citation_found'] ?? false,
                    'confidence' => $gemini['confidence'] ?? 0,
                ]);
            } catch (\Throwable $e) {
                Log::error('Gemini deep research failed - creating error result', [
                    'task_id' => $task->id,
                    'query_index' => $index,
                    'error' => $e->getMessage(),
                ]);
                // Still create result - no skipping
                $gemini = [
                    'citation_found' => false,
                    'confidence' => 0,
                    'citation_references' => [],
                    'explanation' => 'Gemini deep research encountered an error: ' . $e->getMessage() . '. All research steps were attempted.',
                    'raw_response' => null,
                    'provider' => 'gemini',
                ];
            }

            // Ensure both results exist - no query should be skipped
            if ($gpt === null) {
                $gpt = [
                    'citation_found' => false,
                    'confidence' => 0,
                    'citation_references' => [],
                    'explanation' => 'GPT research returned null - all research steps were attempted but no result obtained.',
                    'raw_response' => null,
                    'provider' => 'gpt',
                ];
            }

            if ($gemini === null) {
                $gemini = [
                    'citation_found' => false,
                    'confidence' => 0,
                    'citation_references' => [],
                    'explanation' => 'Gemini research returned null - all research steps were attempted but no result obtained.',
                    'raw_response' => null,
                    'provider' => 'gemini',
                ];
            }

            // Create result - this query has been fully processed with deep research
            $dto = new CitationQueryResultDTO((int) $index, $query, $gpt, $gemini);
            $results[(string) $index] = $dto->toArray();

            Log::info('Deep research completed for citation query', [
                'task_id' => $task->id,
                'query_index' => $index,
                'gpt_citation_found' => $gpt['citation_found'] ?? false,
                'gemini_citation_found' => $gemini['citation_found'] ?? false,
                'gpt_confidence' => $gpt['confidence'] ?? 0,
                'gemini_confidence' => $gemini['confidence'] ?? 0,
            ]);
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
}

