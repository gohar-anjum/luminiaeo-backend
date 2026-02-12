<?php

namespace App\Jobs;

use App\Interfaces\CitationRepositoryInterface;
use App\Models\CitationTask;
use App\Services\CitationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateCitationQueriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 300; // 5 minutes for LLM calls

    public function __construct(public int $taskId, public string $url, public int $numQueries)
    {
        $this->onQueue('citations');
    }

    public function handle(CitationRepositoryInterface $repository, CitationService $service): void
    {
        $task = $repository->find($this->taskId);

        if (!$task) {
            Log::warning('Citation task not found for query generation', ['task_id' => $this->taskId]);
            return;
        }

        // Check if task is already failed or completed
        if (in_array($task->status, [CitationTask::STATUS_FAILED, CitationTask::STATUS_COMPLETED])) {
            Log::info('Citation task already in final state, skipping query generation', [
                'task_id' => $task->id,
                'status' => $task->status,
            ]);
            return;
        }

        try {
            // Fetch queries from FAQ sources (SERP + AlsoAsked), same pipeline as FAQ generator
            $queries = $service->fetchQueriesFromFaqSources($this->url, null, $this->numQueries);

            if (empty($queries)) {
                Log::warning('No questions from SERP or AlsoAsked for citation task', [
                    'task_id' => $task->id,
                    'url' => $this->url,
                ]);
                $repository->update($task, [
                    'status' => CitationTask::STATUS_FAILED,
                    'meta' => array_merge($task->meta ?? [], [
                        'error' => 'No questions found from SERP or AlsoAsked. Ensure SERP and AlsoAsked services are configured.',
                    ]),
                ]);
                return;
            }

            // Cap to requested count (FAQ source may return fewer)
            $queries = array_slice(array_values($queries), 0, $this->numQueries);

            // Update task with queries and queue processing
            $repository->update($task, [
                'queries' => $queries,
                'status' => CitationTask::STATUS_QUEUED,
            ]);

            // Dispatch processing job
            ProcessCitationTaskJob::dispatch($task->id);

            Log::info('Citation queries fetched from SERP/AlsoAsked and processing queued', [
                'task_id' => $task->id,
                'queries_count' => count($queries),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch citation queries from SERP/AlsoAsked', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $service->recordFailure($task, 'Query fetch from SERP/AlsoAsked failed: ' . $e->getMessage());
        }
    }
}

