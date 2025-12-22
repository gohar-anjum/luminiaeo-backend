<?php

namespace App\Jobs;

use App\DTOs\BacklinkDTO;
use App\Exceptions\DataForSEOException;
use App\Interfaces\DataForSEO\BacklinksRepositoryInterface;
use App\Models\Backlink;
use App\Models\SeoTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchBacklinksResultsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public $timeout = 300;

    public function backoff(): array
    {

        return [60, 120, 300, 600, 1800];
    }

    protected string $taskId;
    protected string $domain;

    public function __construct(string $taskId, string $domain)
    {
        $this->taskId = $taskId;
        $this->domain = $domain;
    }

    public function handle(BacklinksRepositoryInterface $repo): void
    {
        Log::info('Fetching backlinks results', [
            'domain' => $this->domain,
            'task_id' => $this->taskId,
            'attempt' => $this->attempts(),
        ]);

        try {

            $seoTask = $repo->getTaskStatus($this->taskId);

            if (!$seoTask) {
                Log::error('Task not found in database', [
                    'task_id' => $this->taskId,
                ]);
                $this->fail(new \Exception("Task not found: {$this->taskId}"));
                return;
            }

            if ($seoTask->isCompleted()) {
                Log::info('Task already completed', [
                    'task_id' => $this->taskId,
                ]);
                return;
            }

            if ($seoTask->isFailed() && $seoTask->retry_count >= $this->tries) {
                Log::error('Task failed too many times', [
                    'task_id' => $this->taskId,
                    'retry_count' => $seoTask->retry_count,
                ]);
                $this->fail(new \Exception("Task failed after {$seoTask->retry_count} attempts"));
                return;
            }

            $results = $repo->fetchResults($this->taskId);

            if (isset($results['pending']) && $results['pending'] === true) {
                Log::info('Task still pending, will retry', [
                    'task_id' => $this->taskId,
                    'attempt' => $this->attempts(),
                ]);

                $this->release($this->backoff()[$this->attempts() - 1] ?? 60);
                return;
            }

            if (empty($results) || !is_array($results)) {
                Log::warning('No results or invalid results format', [
                    'task_id' => $this->taskId,
                    'results' => $results,
                ]);

                if ($this->attempts() >= 3) {
                    $repo->updateTaskStatus($this->taskId, SeoTask::STATUS_COMPLETED, []);
                    return;
                }

                $this->release($this->backoff()[$this->attempts() - 1] ?? 60);
                return;
            }

            $backlinks = array_map(function ($item) {
                return BacklinkDTO::fromArray($item, $this->domain, $this->taskId);
            }, $results);

            $this->bulkUpsertBacklinks($backlinks);

            $repo->updateTaskStatus($this->taskId, SeoTask::STATUS_COMPLETED, $results);

            Log::info('Successfully stored backlinks', [
                'domain' => $this->domain,
                'task_id' => $this->taskId,
                'count' => count($backlinks),
            ]);
        } catch (DataForSEOException $e) {
            Log::error('DataForSEO error in job', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ]);

            $repo->updateTaskStatus($this->taskId, SeoTask::STATUS_FAILED, null, $e->getMessage());

            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 60);
                return;
            }

            $this->fail($e);
        } catch (\Exception $e) {
            Log::error('Unexpected error in job', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $repo->updateTaskStatus($this->taskId, SeoTask::STATUS_FAILED, null, $e->getMessage());

            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 60);
                return;
            }

            $this->fail($e);
        }
    }

    protected function bulkUpsertBacklinks(array $backlinks): void
    {
        if (empty($backlinks)) {
            return;
        }

        $data = array_map(function (BacklinkDTO $dto) {
            $array = $dto->toDatabaseArray();

            $array['created_at'] = now();
            return $array;
        }, $backlinks);

        Backlink::upsert(
            $data,
            ['domain', 'source_url', 'task_id'],
            ['anchor', 'link_type', 'source_domain', 'domain_rank', 'updated_at']
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Job failed permanently', [
            'task_id' => $this->taskId,
            'domain' => $this->domain,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        try {
            $repo = app(BacklinksRepositoryInterface::class);
            $repo->updateTaskStatus($this->taskId, SeoTask::STATUS_FAILED, null, $exception->getMessage());
        } catch (\Exception $e) {
            Log::error('Failed to update task status', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
