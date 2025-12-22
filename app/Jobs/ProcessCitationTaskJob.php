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

class ProcessCitationTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;

    public function __construct(public int $taskId)
    {
        $this->onQueue('citations');
    }

    public function handle(CitationRepositoryInterface $repository, CitationService $service): void
    {
        $task = $repository->find($this->taskId);

        if (!$task) {
            Log::warning('Citation task not found for processing', ['task_id' => $this->taskId]);
            return;
        }

        if (empty($task->queries)) {
            Log::error('Citation task missing queries', ['task_id' => $task->id]);
            $service->recordFailure($task, 'No queries generated');
            return;
        }

        if ($task->status !== CitationTask::STATUS_PROCESSING) {
            $repository->update($task, ['status' => CitationTask::STATUS_PROCESSING]);
        }

        $service->dispatchChunkJobs($task);
    }
}
