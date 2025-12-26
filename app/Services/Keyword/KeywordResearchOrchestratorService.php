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

        if ($settings['enable_clustering'] ?? true) {
            $numClusters = min(10, max(3, (int) (count($allKeywords) / 20)));

            $clusteringResult = $this->clusteringService->clusterKeywords($allKeywords, $numClusters, true);

            $this->storeClusters($job, $clusteringResult);
        }

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
                
                if ($useDataForSEOPlanner && $this->dataForSEOService) {
                    Log::info('Falling back to DataForSEO Keyword Planner', ['job_id' => $job->id]);
                    $useGooglePlanner = false;
                }
            }
        }

        if (($useDataForSEOPlanner || !$useGooglePlanner) && $this->dataForSEOService) {
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

        if ($settings['enable_scraper'] ?? true) {
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
        }

        if ($settings['enable_combined_keywords'] ?? true) {
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
        }

        return $this->deduplicateKeywords($allKeywords);
    }

    protected function storeKeywords(KeywordResearchJob $job, array $keywords): array
    {
        $keywordIds = [];

        // Load user relationship if needed for project_id
        if (!$job->relationLoaded('user')) {
            $job->load('user');
        }

        DB::transaction(function () use ($job, $keywords, &$keywordIds) {
            $batchSize = 500;
            $batches = array_chunk($keywords, $batchSize);

            // Get available columns from database - check once per transaction
            $keywordColumns = [];
            try {
                $columns = Schema::getColumnListing('keywords');
                // Convert to associative array for faster lookups
                $keywordColumns = array_flip($columns);
            } catch (\Exception $e) {
                // If we can't get columns, use empty array - only basic columns will be inserted
                $keywordColumns = [];
            }

            foreach ($batches as $batch) {
                $insertData = [];
                $now = now();

                foreach ($batch as $keywordDTO) {
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

                    // Include required columns if they exist in database
                    if (!empty($keywordColumns)) {
                        // project_id is required if column exists - get from user
                        if (isset($keywordColumns['project_id'])) {
                            // User should already be loaded before transaction
                            $row['project_id'] = $job->user->project_id ?? $job->user_id ?? null;
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
                        if (isset($keywordColumns['question_variations']) && $keywordDTO->questionVariations) {
                            $row['question_variations'] = json_encode($keywordDTO->questionVariations);
                        }
                        if (isset($keywordColumns['long_tail_versions']) && $keywordDTO->longTailVersions) {
                            $row['long_tail_versions'] = json_encode($keywordDTO->longTailVersions);
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

                Keyword::insert($insertData);

                // Get inserted keyword IDs - only use keyword_research_job_id if column exists
                $keywordTexts = array_map(fn($dto) => $dto->keyword, $batch);
                $query = Keyword::whereIn('keyword', $keywordTexts);
                
                if (isset($keywordColumns['keyword_research_job_id'])) {
                    $query->where('keyword_research_job_id', $job->id);
                }
                
                $insertedKeywords = $query->pluck('id')->toArray();
                $keywordIds = array_merge($keywordIds, $insertedKeywords);
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
                    Keyword::where('keyword_research_job_id', $job->id)
                        ->where('keyword', $keywordText)
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

        $keywords = $job->keywords()
            ->with('cluster:id,topic_name,description')
            ->select([
                'id', 'keyword', 'source', 'search_volume', 'competition', 'cpc',
                'intent_category', 'ai_visibility_score', 'keyword_cluster_id', 'question_variations'
            ])
            ->get();

        $clusters = $job->clusters()
            ->withCount('keywords')
            ->get();

        return [
            'summary' => [
                'total_keywords' => $keywords->count(),
                'total_clusters' => $clusters->count(),
                'sources' => $keywords->groupBy('source')->map->count(),
                'avg_ai_visibility_score' => $keywords->whereNotNull('ai_visibility_score')->avg('ai_visibility_score'),
            ],
            'keywords' => $keywords->map(function ($keyword) {
                return [
                    'id' => $keyword->id,
                    'keyword' => $keyword->keyword,
                    'source' => $keyword->source,
                    'search_volume' => $keyword->search_volume,
                    'competition' => $keyword->competition,
                    'cpc' => $keyword->cpc,
                    'intent_category' => $keyword->intent_category,
                    'ai_visibility_score' => $keyword->ai_visibility_score,
                    'cluster_id' => $keyword->keyword_cluster_id,
                    'question_variations' => $keyword->question_variations,
                ];
            }),
            'clusters' => $clusters->map(function ($cluster) {
                return [
                    'id' => $cluster->id,
                    'topic_name' => $cluster->topic_name,
                    'description' => $cluster->description,
                    'keyword_count' => $cluster->keyword_count,
                    'suggested_article_titles' => $cluster->suggested_article_titles,
                    'recommended_faq_questions' => $cluster->recommended_faq_questions,
                    'schema_suggestions' => $cluster->schema_suggestions,
                    'ai_visibility_projection' => $cluster->ai_visibility_projection,
                ];
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
