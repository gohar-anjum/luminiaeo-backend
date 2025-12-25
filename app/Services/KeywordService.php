<?php

namespace App\Services;

use App\DTOs\KeywordResearchRequestDTO;
use App\Interfaces\KeywordRepositoryInterface;
use App\Jobs\ProcessKeywordResearchJob;
use App\Models\KeywordResearchJob as KeywordResearchJobModel;
use App\Services\Keyword\KeywordResearchOrchestratorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
        // Get available columns from database
        $columns = [];
        try {
            $columns = Schema::getColumnListing('keyword_research_jobs');
        } catch (\Exception $e) {
            // If we can't get columns, use basic structure
        }

        // Start with only the columns that definitely exist
        $data = [
            'user_id' => Auth::id(),
            'query' => $dto->query,
            'status' => KeywordResearchJobModel::STATUS_PENDING,
        ];

        // Only include optional columns if they exist in the database
        if (!empty($columns)) {
            if (in_array('project_id', $columns) && $dto->projectId !== null) {
                $data['project_id'] = $dto->projectId;
            }
            
            if (in_array('language_code', $columns) && $dto->languageCode !== null) {
                $data['language_code'] = $dto->languageCode;
            }
            
            if (in_array('geoTargetId', $columns) && $dto->geoTargetId !== null) {
                $data['geoTargetId'] = $dto->geoTargetId;
            }
            
            if (in_array('settings', $columns)) {
                $data['settings'] = [
                    'max_keywords' => $dto->maxKeywords,
                    'enable_google_planner' => $dto->enableGooglePlanner,
                    'enable_scraper' => $dto->enableScraper,
                    'enable_answerthepublic' => $dto->enableAnswerThePublic,
                    'enable_clustering' => $dto->enableClustering,
                    'enable_intent_scoring' => $dto->enableIntentScoring,
                ];
            }
            
            if (in_array('progress', $columns)) {
                $data['progress'] = [
                    'queued' => [
                        'percentage' => 0,
                        'timestamp' => now()->toIso8601String(),
                    ],
                ];
            }
        }

        $job = KeywordResearchJobModel::create($data);

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
        // Check if relationship columns exist before eager loading
        $withRelations = [];
        try {
            $keywordColumns = Schema::getColumnListing('keywords');
            $clusterColumns = Schema::getColumnListing('keyword_clusters');
            
            if (in_array('keyword_research_job_id', $keywordColumns)) {
                $withRelations[] = 'keywords';
            }
            
            if (in_array('keyword_research_job_id', $clusterColumns)) {
                $withRelations[] = 'clusters';
            }
        } catch (\Exception $e) {
            // If we can't check, don't load relationships
        }
        
        $job = !empty($withRelations) 
            ? KeywordResearchJobModel::with($withRelations)->findOrFail($jobId)
            : KeywordResearchJobModel::findOrFail($jobId);

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
        // Check if relationship columns exist before eager loading
        $withRelations = [];
        try {
            $keywordColumns = Schema::getColumnListing('keywords');
            $clusterColumns = Schema::getColumnListing('keyword_clusters');
            
            if (in_array('keyword_research_job_id', $keywordColumns)) {
                // Check if keywords table has cluster relationship
                if (in_array('keyword_cluster_id', $keywordColumns)) {
                    $withRelations[] = 'keywords.cluster';
                } else {
                    $withRelations[] = 'keywords';
                }
            }
            
            if (in_array('keyword_research_job_id', $clusterColumns)) {
                $withRelations[] = 'clusters';
            }
        } catch (\Exception $e) {
            // If we can't check, don't load relationships
        }
        
        $job = !empty($withRelations) 
            ? KeywordResearchJobModel::with($withRelations)->findOrFail($jobId)
            : KeywordResearchJobModel::findOrFail($jobId);

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
