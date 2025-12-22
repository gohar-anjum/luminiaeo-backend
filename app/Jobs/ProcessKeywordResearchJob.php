<?php

namespace App\Jobs;

use App\Models\KeywordResearchJob;
use App\Services\Keyword\KeywordResearchOrchestratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessKeywordResearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 600;

    public function __construct(public int $jobId)
    {
    }

    public function handle(KeywordResearchOrchestratorService $orchestrator): void
    {
        $job = KeywordResearchJob::find($this->jobId);

        if (!$job) {
            Log::warning('Keyword research job not found', ['job_id' => $this->jobId]);
            return;
        }

        if ($job->status === KeywordResearchJob::STATUS_COMPLETED) {
            Log::info('Keyword research job already completed', ['job_id' => $job->id]);
            return;
        }

        if ($job->status === KeywordResearchJob::STATUS_FAILED) {
            Log::info('Keyword research job already failed', ['job_id' => $job->id]);
            return;
        }

        try {
            $job->update([
                'status' => KeywordResearchJob::STATUS_PROCESSING,
                'started_at' => now(),
            ]);

            $orchestrator->process($job);

            $job->update([
                'status' => KeywordResearchJob::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            Log::info('Keyword research job completed', ['job_id' => $job->id]);
        } catch (\Throwable $e) {
            Log::error('Keyword research job failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $job->update([
                'status' => KeywordResearchJob::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }
}
