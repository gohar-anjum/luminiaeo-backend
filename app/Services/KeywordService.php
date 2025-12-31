<?php

namespace App\Services;

use App\DTOs\KeywordResearchRequestDTO;
use App\Interfaces\KeywordRepositoryInterface;
use App\Jobs\ProcessKeywordResearchJob;
use App\Models\KeywordResearchJob as KeywordResearchJobModel;
use App\Services\Keyword\KeywordResearchOrchestratorService;
use App\Services\KeywordResearchJobRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class KeywordService
{
    protected KeywordRepositoryInterface $keywordRepository;
    protected ApiResponseModifier $response;
    protected KeywordResearchOrchestratorService $orchestrator;
    protected KeywordResearchJobRepository $jobRepository;

    public function __construct(
        KeywordRepositoryInterface $keywordRepository,
        ApiResponseModifier $response,
        KeywordResearchOrchestratorService $orchestrator,
        KeywordResearchJobRepository $jobRepository
    ) {
        $this->keywordRepository = $keywordRepository;
        $this->response = $response;
        $this->orchestrator = $orchestrator;
        $this->jobRepository = $jobRepository;
    }

    public function createKeywordResearch(KeywordResearchRequestDTO $dto): KeywordResearchJobModel
    {
        $userId = Auth::id();
        
        // Check for duplicate job (request deduplication)
        $lockKey = 'keyword_research:lock:' . md5($userId . ':' . $dto->query);
        $timeout = config('cache_locks.keyword_research.timeout', 10);
        
        return Cache::lock($lockKey, $timeout)->get(function () use ($userId, $dto) {
            // Check again after acquiring lock
            $duplicate = $this->jobRepository->findRecentDuplicate($userId, $dto->query, 5);
            if ($duplicate) {
                Log::info('Duplicate keyword research job detected, returning existing job', [
                    'existing_job_id' => $duplicate->id,
                    'user_id' => $userId,
                    'query' => $dto->query,
                ]);
                return $duplicate;
            }

            $baseData = [
                'user_id' => $userId,
                'query' => $dto->query,
                'status' => KeywordResearchJobModel::STATUS_PENDING,
            ];

            $optionalData = [
                'project_id' => $dto->projectId,
                'language_code' => $dto->languageCode,
                'geoTargetId' => $dto->geoTargetId,
                'settings' => [
                    'max_keywords' => $dto->maxKeywords,
                    'enable_google_planner' => $dto->enableGooglePlanner,
                    'enable_scraper' => $dto->enableScraper,
                    'enable_clustering' => $dto->enableClustering,
                    'enable_intent_scoring' => $dto->enableIntentScoring,
                ],
                'progress' => [
                    'queued' => [
                        'percentage' => 0,
                        'timestamp' => now()->toIso8601String(),
                    ],
                ],
            ];

            $job = $this->jobRepository->createWithOptionalFields($baseData, $optionalData);

            ProcessKeywordResearchJob::dispatch($job->id);

            Log::info('Keyword research job created', [
                'job_id' => $job->id,
                'user_id' => $userId,
                'query' => $dto->query,
            ]);

            return $job;
        });
    }

    public function getKeywordResearchStatus(int $jobId)
    {
        $job = KeywordResearchJobModel::with(['keywords'/*, 'clusters'*/])->findOrFail($jobId);

        if ($job->user_id !== Auth::id()) {
            return $this->response->setMessage('Unauthorized')->setResponseCode(403)->response();
        }

        return $this->response->setData([
            'id' => $job->id,
            'query' => $job->query,
            'status' => $job->status,
            'progress' => $job->progress,
            'result' => $job->result,
            'error_message' => $job->error_message,
            'created_at' => $job->created_at,
            'started_at' => $job->started_at,
            'completed_at' => $job->completed_at,
        ])->response();
    }

    public function getKeywordResearchResults(int $jobId)
    {
        $job = KeywordResearchJobModel::with(['keywords'/*, 'keywords.cluster', 'clusters'*/])->findOrFail($jobId);

        if ($job->user_id !== Auth::id()) {
            return $this->response->setMessage('Unauthorized')->setResponseCode(403)->response();
        }

        if ($job->status !== KeywordResearchJobModel::STATUS_COMPLETED) {
            return $this->response->setMessage('Research job not completed yet')->setData([
                'status' => $job->status,
                'progress' => $job->progress,
            ])->response();
        }

        return $this->response->setData($job->result ?? [])->response();
    }

    public function listKeywordResearchJobs(int $perPage = 15)
    {
        $jobs = KeywordResearchJobModel::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
            ->through(function ($job) {
                return [
                    'id' => $job->id,
                    'query' => $job->query,
                    'status' => $job->status,
                    'progress' => $job->progress,
                    'created_at' => $job->created_at,
                    'completed_at' => $job->completed_at,
                ];
            });

        return $this->response->setData($jobs)->response();
    }
}
