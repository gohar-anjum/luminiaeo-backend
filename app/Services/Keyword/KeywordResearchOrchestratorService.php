<?php

namespace App\Services\Keyword;

use App\DTOs\KeywordDataDTO;
use App\Jobs\ProcessKeywordIntentJob;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordResearchJob;
use App\Services\Google\KeywordPlannerService;
use App\Services\DataForSEO\DataForSEOService;
use App\Services\Keyword\KeywordScraperService;
use App\Services\Keyword\SemanticClusteringService;
use App\Services\Keyword\KeywordCacheService;
use App\Services\Keyword\KeywordClusteringCacheService;
use App\Services\Keyword\CombinedKeywordService;
use App\Services\LLM\LLMClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class KeywordResearchOrchestratorService
{
    public function __construct(
        protected KeywordPlannerService $keywordPlannerService,
        protected ?DataForSEOService $dataForSEOService,
        protected KeywordScraperService $scraperService,
        protected SemanticClusteringService $clusteringService,
        protected KeywordCacheService $cacheService,
        protected KeywordClusteringCacheService $clusteringCacheService,
        protected CombinedKeywordService $combinedKeywordService,
        protected LLMClient $llmClient
    ) {
    }

    public function process(KeywordResearchJob $job): void
    {
        $settings = is_array($job->settings) ? $job->settings : [];
        $maxKeywords = isset($settings['max_keywords']) && is_numeric($settings['max_keywords']) && $settings['max_keywords'] > 0 
            ? (int) $settings['max_keywords'] 
            : null;

        $this->updateProgress($job, 'collecting', 10);

        $allKeywords = $this->collectKeywords($job, $settings);

        if (empty($allKeywords)) {
            $errorMessage = 'No keywords collected from any source. Please check your API configurations (DataForSEO, Google Keyword Planner) and try again with a different query.';
            $job->update([
                'status' => KeywordResearchJob::STATUS_FAILED,
                'error_message' => $errorMessage,
                'completed_at' => now(),
            ]);
            throw new \RuntimeException($errorMessage);
        }

        if ($maxKeywords && count($allKeywords) > $maxKeywords) {
            $allKeywords = array_slice($allKeywords, 0, $maxKeywords);
        }

        $this->updateProgress($job, 'storing', 30);

        $keywordIds = $this->storeKeywords($job, $allKeywords);

        $this->updateProgress($job, 'clustering', 50);

        // Clustering functionality temporarily disabled - keyword_clusters table not available
        // if ($settings['enable_clustering'] ?? true) {
        //     $numClusters = min(10, max(3, (int) (count($allKeywords) / 20)));
        //
        //     $clusteringResult = $this->clusteringService->clusterKeywords($allKeywords, $numClusters, true);
        //
        //     $this->storeClusters($job, $clusteringResult);
        // }

        $this->updateProgress($job, 'intent_scoring', 70);

        if ($settings['enable_intent_scoring'] ?? true) {
            $this->scoreKeywords($keywordIds);
        }

        $this->updateProgress($job, 'finalizing', 90);

        $result = $this->generateResult($job);

        $job->update([
            'result' => $result,
        ]);

        $this->updateProgress($job, 'completed', 100);
    }

    protected function collectKeywords(KeywordResearchJob $job, array $settings): array
    {
        $allKeywords = [];
        $seedKeyword = $job->query;
        $languageCode = $job->language_code ?? 'en';
        $geoTargetId = (string) ($job->geoTargetId ?? 2840);

        $useGooglePlanner = $settings['enable_google_planner'] ?? true;
        $useDataForSEOPlanner = config('services.dataforseo.keyword_planner_enabled', false);
        
        // Skip Google Planner if DataForSEO is enabled via config
        if ($useDataForSEOPlanner) {
            Log::info('Skipping Google Keyword Planner - DataForSEO service is active', [
                'job_id' => $job->id,
            ]);
            $useGooglePlanner = false;
        }
        
        if ($useGooglePlanner && !$useDataForSEOPlanner) {
            try {
                $maxPlannerKeywords = null;
                if (isset($settings['max_keywords']) && is_numeric($settings['max_keywords']) && $settings['max_keywords'] > 0) {
                    $maxPlannerKeywords = (int) $settings['max_keywords'];
                }
                
                $plannerKeywords = $this->keywordPlannerService->getKeywordIdeas(
                    $seedKeyword,
                    $this->mapLanguageCode($languageCode),
                    $geoTargetId,
                    $maxPlannerKeywords
                );
                $allKeywords = array_merge($allKeywords, $plannerKeywords);

                Log::info('Google Keyword Planner completed', [
                    'job_id' => $job->id,
                    'keywords_found' => count($plannerKeywords),
                ]);
            } catch (\Throwable $e) {
                Log::error('Google Keyword Planner failed', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Use DataForSEO if enabled via config
        if ($useDataForSEOPlanner) {
            try {
                $maxPlannerKeywords = null;
                if (isset($settings['max_keywords']) && is_numeric($settings['max_keywords']) && $settings['max_keywords'] > 0) {
                    $maxPlannerKeywords = (int) $settings['max_keywords'];
                }
                
                $plannerKeywords = $this->dataForSEOService->getKeywordIdeas(
                    $seedKeyword,
                    $languageCode,
                    (int) $geoTargetId,
                    $maxPlannerKeywords
                );
                $allKeywords = array_merge($allKeywords, $plannerKeywords);

                Log::info('DataForSEO Keyword Planner completed', [
                    'job_id' => $job->id,
                    'keywords_found' => count($plannerKeywords),
                ]);
            } catch (\Throwable $e) {
                Log::error('DataForSEO Keyword Planner failed', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

       /* if ($settings['enable_scraper'] ?? true) {
            try {
                $scraperKeywords = $this->scraperService->scrapeAll($seedKeyword, $languageCode);
                $allKeywords = array_merge($allKeywords, $scraperKeywords);

                Log::info('Keyword scraper completed', [
                    'job_id' => $job->id,
                    'keywords_found' => count($scraperKeywords),
                ]);
            } catch (\Throwable $e) {
                Log::error('Keyword scraper failed', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }*/

       /* if ($settings['enable_combined_keywords'] ?? true) {
            try {
                $maxCombinedKeywords = null;
                if (isset($settings['max_keywords']) && is_numeric($settings['max_keywords']) && $settings['max_keywords'] > 0) {
                    $maxCombinedKeywords = (int) ($settings['max_keywords'] * 0.4);
                }
                
                $combinedKeywords = $this->combinedKeywordService->getCombinedKeywords(
                    $seedKeyword,
                    (int) $geoTargetId,
                    $languageCode,
                    $maxCombinedKeywords
                );
                $allKeywords = array_merge($allKeywords, $combinedKeywords);

                Log::info('Combined keywords (DataForSEO + AlsoAsked) completed', [
                    'job_id' => $job->id,
                    'keywords_found' => count($combinedKeywords),
                ]);
            } catch (\Throwable $e) {
                Log::error('Combined keywords failed', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }*/

        return $this->deduplicateKeywords($allKeywords);
    }

    protected function storeKeywords(KeywordResearchJob $job, array $keywords): array
    {
        $keywordIds = [];

        if (!$job->relationLoaded('user')) {
            $job->load('user');
        }

        DB::transaction(function () use ($job, $keywords, &$keywordIds) {
            $batchSize = 500;
            $batches = array_chunk($keywords, $batchSize);

            // Detect which columns exist in the keywords table
            $existingColumns = Schema::getColumnListing('keywords');
            $keywordColumns = array_flip($existingColumns);

            $projectId = $job->project_id ?? null;
            $projectSource = null;
            
            if ($projectId) {
                $projectSource = 'job';
            } elseif ($job->user_id) {
                Log::info('Job does not have project_id, attempting to get user\'s first project', [
                    'job_id' => $job->id,
                    'user_id' => $job->user_id,
                ]);
                
                $firstProject = DB::table('projects')
                    ->where('user_id', $job->user_id)
                    ->first();
                $projectId = $firstProject?->id ?? null;
                
                if ($projectId) {
                    $projectSource = 'user_first_project';
                    Log::info('Found project_id from user\'s first project', [
                        'job_id' => $job->id,
                        'project_id' => $projectId,
                    ]);
                }
            }
            
            if ($projectId === null && $job->user_id) {
                Log::info('User has no projects, auto-creating default project', [
                    'job_id' => $job->id,
                    'user_id' => $job->user_id,
                ]);
                
                try {
                    $projectId = DB::table('projects')->insertGetId([
                        'user_id' => $job->user_id,
                        'name' => 'Default Project',
                        'domain' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    
                    $projectSource = 'auto_created';
                    Log::info('Auto-created default project for user', [
                        'job_id' => $job->id,
                        'user_id' => $job->user_id,
                        'project_id' => $projectId,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to auto-create default project', [
                        'job_id' => $job->id,
                        'user_id' => $job->user_id,
                        'error' => $e->getMessage(),
                    ]);
                    throw new \RuntimeException(
                        'Cannot insert keywords: project_id is required. Failed to auto-create default project: ' . $e->getMessage()
                    );
                }
            }
            
            if ($projectId === null) {
                Log::error('Cannot resolve project_id for keyword insertion', [
                    'job_id' => $job->id,
                    'job_project_id' => $job->project_id,
                    'user_id' => $job->user_id,
                ]);
                
                throw new \RuntimeException(
                    'Cannot insert keywords: project_id is required but not found. ' .
                    'Please ensure the keyword research job has a valid project_id or the user has at least one project.'
                );
            }
            
            Log::info('Resolved project_id for keyword insertion', [
                'job_id' => $job->id,
                'project_id' => $projectId,
                'source' => $projectSource,
            ]);

            foreach ($batches as $batch) {
                $insertData = [];
                $existingKeywordIds = [];
                $now = now();

                foreach ($batch as $keywordDTO) {
                    // Check if keyword already exists for this job
                    $existingKeyword = null;
                    if (isset($keywordColumns['keyword_research_job_id'])) {
                        $existingKeyword = Keyword::where('keyword', $keywordDTO->keyword)
                            ->where('keyword_research_job_id', $job->id)
                            ->first();
                    } else {
                        $existingKeyword = Keyword::where('keyword', $keywordDTO->keyword)
                            ->where('project_id', $projectId)
                            ->first();
                    }

                    if ($existingKeyword) {
                        // Keyword already exists for this job, use existing ID
                        $existingKeywordIds[] = $existingKeyword->id;
                        continue;
                    }

                    $row = [
                        'keyword' => $keywordDTO->keyword,
                        'search_volume' => $keywordDTO->searchVolume,
                        'competition' => $keywordDTO->competition,
                        'cpc' => $keywordDTO->cpc,
                        'intent' => $keywordDTO->intent ?? null,
                        'location' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (!empty($keywordColumns)) {
                        if (isset($keywordColumns['project_id'])) {
                            $row['project_id'] = $projectId;
                        }
                        if (isset($keywordColumns['keyword_research_job_id'])) {
                            $row['keyword_research_job_id'] = $job->id;
                        }
                        if (isset($keywordColumns['source'])) {
                            $row['source'] = $keywordDTO->source ?? null;
                        }
                        if (isset($keywordColumns['language_code'])) {
                            $row['language_code'] = $job->language_code ?? 'en';
                        }
                        if (isset($keywordColumns['geoTargetId'])) {
                            $row['geoTargetId'] = $job->geoTargetId ?? 2840;
                        }
                        if (isset($keywordColumns['intent_category'])) {
                            $row['intent_category'] = $keywordDTO->intentCategory ?? null;
                        }
                        if (isset($keywordColumns['intent_metadata']) && $keywordDTO->intentMetadata) {
                            $row['intent_metadata'] = json_encode($keywordDTO->intentMetadata);
                        }
                        if (isset($keywordColumns['ai_visibility_score'])) {
                            $row['ai_visibility_score'] = $keywordDTO->aiVisibilityScore ?? null;
                        }
                        if (isset($keywordColumns['semantic_data']) && $keywordDTO->semanticData) {
                            $row['semantic_data'] = json_encode($keywordDTO->semanticData);
                        }
                    }

                    $insertData[] = $row;
                }

                // Insert new keywords
                if (!empty($insertData)) {
                    try {
                        Keyword::insert($insertData);
                    } catch (\Exception $e) {
                        Log::warning('Some keywords may have failed to insert', [
                            'job_id' => $job->id,
                            'error' => $e->getMessage(),
                            'batch_size' => count($insertData),
                        ]);
                    }
                }

                // Get IDs of all keywords for this job (both newly inserted and existing)
                $keywordTexts = array_map(fn($dto) => $dto->keyword, $batch);
                $query = Keyword::whereIn('keyword', $keywordTexts);
                
                if (isset($keywordColumns['keyword_research_job_id'])) {
                    $query->where('keyword_research_job_id', $job->id);
                } else {
                    $query->where('project_id', $projectId);
                }
                
                $insertedKeywords = $query->pluck('id')->toArray();
                $keywordIds = array_merge($keywordIds, $insertedKeywords, $existingKeywordIds);
            }
        });

        return $keywordIds;
    }

    protected function storeClusters(KeywordResearchJob $job, array $clusteringResult): void
    {
        DB::transaction(function () use ($job, $clusteringResult) {
            $clusterModels = [];

            foreach ($clusteringResult['clusters'] as $index => $clusterDTO) {
                $cluster = KeywordCluster::create([
                    'keyword_research_job_id' => $job->id,
                    'topic_name' => $clusterDTO->topicName,
                    'description' => $clusterDTO->description,
                    'suggested_article_titles' => $clusterDTO->suggestedArticleTitles,
                    'recommended_faq_questions' => $clusterDTO->recommendedFaqQuestions,
                    'schema_suggestions' => $clusterDTO->schemaSuggestions,
                    'ai_visibility_projection' => $clusterDTO->aiVisibilityProjection,
                    'keyword_count' => $clusterDTO->keywordCount,
                ]);

                $clusterModels[$index] = $cluster;
            }

            foreach ($clusteringResult['keyword_cluster_map'] as $keywordText => $clusterIndex) {
                if (isset($clusterModels[$clusterIndex])) {
                    Keyword::where('keyword', $keywordText)
                        ->where('keyword_research_job_id', $job->id)
                        ->update(['keyword_cluster_id' => $clusterModels[$clusterIndex]->id]);
                }
            }
        });
    }

    protected function scoreKeywords(array $keywordIds): void
    {
        foreach ($keywordIds as $keywordId) {
            ProcessKeywordIntentJob::dispatch($keywordId);
        }
    }

    protected function generateResult(KeywordResearchJob $job): array
    {
        // Get available columns to avoid selecting non-existent columns
        $existingColumns = Schema::getColumnListing('keywords');
        $columnMap = array_flip($existingColumns);
        
        // Build select array with only existing columns
        $selectColumns = ['id', 'keyword', 'search_volume', 'competition', 'cpc'];
        $optionalColumns = ['source', 'intent_category', 'ai_visibility_score'];
        
        foreach ($optionalColumns as $col) {
            if (isset($columnMap[$col])) {
                $selectColumns[] = $col;
            }
        }
        
        // Explicitly filter out question_variations if it somehow got added
        $selectColumns = array_filter($selectColumns, fn($col) => $col !== 'question_variations');
        
        Log::debug('Keyword select columns', [
            'columns' => $selectColumns,
            'existing_columns' => $existingColumns,
        ]);
        
        $keywords = $job->keywords()
            ->select($selectColumns)
            ->get();

        // Calculate sources safely
        $sources = [];
        if (isset($columnMap['source'])) {
            $sources = $keywords->groupBy('source')->map->count()->toArray();
        }
        
        // Calculate avg AI visibility score safely
        $avgAiVisibilityScore = null;
        if (isset($columnMap['ai_visibility_score'])) {
            $avgAiVisibilityScore = $keywords->whereNotNull('ai_visibility_score')->avg('ai_visibility_score');
        }

        return [
            'summary' => [
                'total_keywords' => $keywords->count(),
                'sources' => $sources,
                'avg_ai_visibility_score' => $avgAiVisibilityScore,
            ],
            'keywords' => $keywords->map(function ($keyword) use ($columnMap) {
                $result = [
                    'id' => $keyword->id,
                    'keyword' => $keyword->keyword,
                    'search_volume' => $keyword->search_volume,
                    'competition' => $keyword->competition,
                    'cpc' => $keyword->cpc,
                ];
                
                // Add optional fields only if they exist
                if (isset($columnMap['source'])) {
                    $result['source'] = $keyword->source;
                }
                if (isset($columnMap['intent_category'])) {
                    $result['intent_category'] = $keyword->intent_category;
                }
                if (isset($columnMap['ai_visibility_score'])) {
                    $result['ai_visibility_score'] = $keyword->ai_visibility_score;
                }
                
                return $result;
            }),
        ];
    }

    protected function deduplicateKeywords(array $keywords): array
    {
        $seen = [];
        $unique = [];

        foreach ($keywords as $keyword) {
            $normalized = strtolower(trim($keyword->keyword));
            if (!isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $unique[] = $keyword;
            }
        }

        return $unique;
    }

    protected function mapLanguageCode(string $code): string
    {
        $map = [
            'en' => '1000',
            'es' => '1003',
            'fr' => '1002',
            'de' => '1001',
            'it' => '1004',
            'pt' => '1014',
            'ja' => '1005',
            'zh' => '1017',
            'ru' => '1009',
        ];

        return $map[$code] ?? '1000';
    }

    protected function updateProgress(KeywordResearchJob $job, string $stage, int $percentage): void
    {
        $progress = $job->progress ?? [];
        $progress[$stage] = [
            'percentage' => $percentage,
            'timestamp' => now()->toIso8601String(),
        ];

        $job->update(['progress' => $progress]);
    }
}
