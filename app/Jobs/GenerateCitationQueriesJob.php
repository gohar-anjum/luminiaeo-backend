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
            $queries = $service->generateQueries($this->url, $this->numQueries);

            if (empty($queries)) {
                Log::warning('Citation task: LLM returned no queries', [
                    'task_id' => $task->id,
                    'url' => $this->url,
                ]);
                $repository->update($task, [
                    'status' => CitationTask::STATUS_FAILED,
                    'meta' => array_merge($task->meta ?? [], [
                        'error' => 'No queries could be generated. Configure OPENAI_API_KEY and/or GOOGLE_API_KEY (Gemini) for citations (config/citations.php) and ensure at least one provider is available.',
                    ]),
                ]);
                return;
            }

            $queries = array_slice(array_values($queries), 0, $this->numQueries);

            // Update task with queries and queue processing
            $repository->update($task, [
                'queries' => $queries,
                'status' => CitationTask::STATUS_QUEUED,
            ]);

            // Dispatch processing job
            ProcessCitationTaskJob::dispatch($task->id);

            Log::info('Citation queries generated via LLM; processing queued', [
                'task_id' => $task->id,
                'queries_count' => count($queries),
            ]);
        } catch (\Exception $e) {
            Log::error('Citation query generation (LLM) failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $service->recordFailure($task, 'LLM query generation failed: '.$e->getMessage());
        }
    }
}

