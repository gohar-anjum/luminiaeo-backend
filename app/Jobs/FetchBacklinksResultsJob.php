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

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    /**
     * The maximum number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array
     */
    public function backoff(): array
    {
        // Exponential backoff: 1 min, 2 min, 5 min, 10 min, 30 min
        return [60, 120, 300, 600, 1800];
    }

    protected string $taskId;
    protected string $domain;

    public function __construct(string $taskId, string $domain)
    {
        $this->taskId = $taskId;
        $this->domain = $domain;
    }

    /**
     * Execute the job.
     *
     * @param BacklinksRepositoryInterface $repo
     * @return void
     */
    public function handle(BacklinksRepositoryInterface $repo): void
    {
        Log::info('Fetching backlinks results', [
            'domain' => $this->domain,
            'task_id' => $this->taskId,
            'attempt' => $this->attempts(),
        ]);

        try {
            // Check task status first
            $seoTask = $repo->getTaskStatus($this->taskId);

            if (!$seoTask) {
                Log::error('Task not found in database', [
                    'task_id' => $this->taskId,
                ]);
                $this->fail(new \Exception("Task not found: {$this->taskId}"));
                return;
            }

            // If task is already completed, skip
            if ($seoTask->isCompleted()) {
                Log::info('Task already completed', [
                    'task_id' => $this->taskId,
                ]);
                return;
            }

            // If task has failed too many times, fail the job
            if ($seoTask->isFailed() && $seoTask->retry_count >= $this->tries) {
                Log::error('Task failed too many times', [
                    'task_id' => $this->taskId,
                    'retry_count' => $seoTask->retry_count,
                ]);
                $this->fail(new \Exception("Task failed after {$seoTask->retry_count} attempts"));
                return;
            }

            // Fetch results from API
            $results = $repo->fetchResults($this->taskId);

            // Check if task is still pending
            if (isset($results['pending']) && $results['pending'] === true) {
                Log::info('Task still pending, will retry', [
                    'task_id' => $this->taskId,
                    'attempt' => $this->attempts(),
                ]);

                // Release job for retry with exponential backoff
                $this->release($this->backoff()[$this->attempts() - 1] ?? 60);
                return;
            }

            // Validate results
            if (empty($results) || !is_array($results)) {
                Log::warning('No results or invalid results format', [
                    'task_id' => $this->taskId,
                    'results' => $results,
                ]);

                // If tried multiple times and still no results, mark as completed with empty results
                if ($this->attempts() >= 3) {
                    $repo->updateTaskStatus($this->taskId, SeoTask::STATUS_COMPLETED, []);
                    return;
                }

                // Retry
                $this->release($this->backoff()[$this->attempts() - 1] ?? 60);
                return;
            }

            // Transform results to DTOs
            $backlinks = array_map(function ($item) {
                return BacklinkDTO::fromArray($item, $this->domain, $this->taskId);
            }, $results);

            // Bulk insert/update using upsert
            $this->bulkUpsertBacklinks($backlinks);

            // Update task status to completed
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

            // Update task status to failed
            $repo->updateTaskStatus($this->taskId, SeoTask::STATUS_FAILED, null, $e->getMessage());

            // Retry if we haven't exceeded max attempts
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 60);
                return;
            }

            // Fail the job after max attempts
            $this->fail($e);
        } catch (\Exception $e) {
            Log::error('Unexpected error in job', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update task status to failed
            $repo->updateTaskStatus($this->taskId, SeoTask::STATUS_FAILED, null, $e->getMessage());

            // Retry if we haven't exceeded max attempts
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 60);
                return;
            }

            // Fail the job after max attempts
            $this->fail($e);
        }
    }

    /**
     * Bulk upsert backlinks
     *
     * @param array $backlinks Array of BacklinkDTO objects
     * @return void
     */
    protected function bulkUpsertBacklinks(array $backlinks): void
    {
        if (empty($backlinks)) {
            return;
        }

        // Prepare data for bulk upsert
        $data = array_map(function (BacklinkDTO $dto) {
            $array = $dto->toDatabaseArray();
            // Add created_at only for new records (upsert will handle this)
            $array['created_at'] = now();
            return $array;
        }, $backlinks);

        // Use upsert to insert or update
        Backlink::upsert(
            $data,
            ['domain', 'source_url', 'task_id'], // Unique keys
            ['anchor', 'link_type', 'source_domain', 'domain_rank', 'updated_at'] // Columns to update
        );
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Job failed permanently', [
            'task_id' => $this->taskId,
            'domain' => $this->domain,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // Update task status to failed
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
