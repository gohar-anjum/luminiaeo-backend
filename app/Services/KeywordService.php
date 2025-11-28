<?php

namespace App\Services;

use App\DTOs\KeywordResearchRequestDTO;
use App\Interfaces\KeywordRepositoryInterface;
use App\Jobs\ProcessKeywordResearchJob;
use App\Models\KeywordResearchJob as KeywordResearchJobModel;
use App\Services\Keyword\KeywordResearchOrchestratorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class KeywordService
{
    protected KeywordRepositoryInterface $keywordRepository;
    protected ApiResponseModifier $response;
    protected KeywordResearchOrchestratorService $orchestrator;

    public function __construct(
        KeywordRepositoryInterface $keywordRepository,
        ApiResponseModifier $response,
        KeywordResearchOrchestratorService $orchestrator
    ) {
        $this->keywordRepository = $keywordRepository;
        $this->response = $response;
        $this->orchestrator = $orchestrator;
    }

    public function createKeywordResearch(KeywordResearchRequestDTO $dto): KeywordResearchJobModel
    {
        $job = KeywordResearchJobModel::create([
            'user_id' => Auth::id(),
            'project_id' => $dto->projectId,
            'query' => $dto->query,
            'status' => KeywordResearchJobModel::STATUS_PENDING,
            'language_code' => $dto->languageCode,
            'geoTargetId' => $dto->geoTargetId,
            'settings' => [
                'max_keywords' => $dto->maxKeywords,
                'enable_google_planner' => $dto->enableGooglePlanner,
                'enable_scraper' => $dto->enableScraper,
                'enable_answerthepublic' => $dto->enableAnswerThePublic,
                'enable_clustering' => $dto->enableClustering,
                'enable_intent_scoring' => $dto->enableIntentScoring,
            ],
            'progress' => [
                'queued' => [
                    'percentage' => 0,
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
        ]);

        ProcessKeywordResearchJob::dispatch($job->id);

        Log::info('Keyword research job created', [
            'job_id' => $job->id,
            'user_id' => Auth::id(),
            'query' => $dto->query,
        ]);

        return $job;
    }

    public function getKeywordResearchStatus(int $jobId)
    {
        $job = KeywordResearchJobModel::with(['keywords', 'clusters'])->findOrFail($jobId);

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
        $job = KeywordResearchJobModel::with(['keywords.cluster', 'clusters'])->findOrFail($jobId);

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

    public function listKeywordResearchJobs()
    {
        $jobs = KeywordResearchJobModel::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($job) {
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
