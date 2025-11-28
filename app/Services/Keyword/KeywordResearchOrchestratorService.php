<?php

namespace App\Services\Keyword;

use App\DTOs\KeywordDataDTO;
use App\Jobs\ProcessKeywordIntentJob;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordResearchJob;
use App\Services\Google\KeywordPlannerService;
use App\Services\Keyword\AnswerThePublicService;
use App\Services\Keyword\KeywordScraperService;
use App\Services\Keyword\SemanticClusteringService;
use App\Services\LLM\LLMClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KeywordResearchOrchestratorService
{
    public function __construct(
        protected KeywordPlannerService $keywordPlannerService,
        protected KeywordScraperService $scraperService,
        protected AnswerThePublicService $atpService,
        protected SemanticClusteringService $clusteringService,
        protected LLMClient $llmClient
    ) {
    }

    public function process(KeywordResearchJob $job): void
    {
        $settings = $job->settings ?? [];
        $maxKeywords = $settings['max_keywords'] ?? null;

        $this->updateProgress($job, 'collecting', 10);

        $allKeywords = $this->collectKeywords($job, $settings);

        if (empty($allKeywords)) {
            throw new \RuntimeException('No keywords collected from any source');
        }

        if ($maxKeywords && count($allKeywords) > $maxKeywords) {
            $allKeywords = array_slice($allKeywords, 0, $maxKeywords);
        }

        $this->updateProgress($job, 'storing', 30);

        $keywordIds = $this->storeKeywords($job, $allKeywords);

        $this->updateProgress($job, 'clustering', 50);

        if ($settings['enable_clustering'] ?? true) {
            $this->clusterKeywords($job, $allKeywords);
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

        if ($settings['enable_google_planner'] ?? true) {
            try {
                $plannerKeywords = $this->keywordPlannerService->getKeywordIdeas(
                    $seedKeyword,
                    $this->mapLanguageCode($languageCode),
                    $geoTargetId,
                    $settings['max_keywords'] ?? null
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

        if ($settings['enable_answerthepublic'] ?? true) {
            try {
                $atpKeywords = $this->atpService->getKeywordData($seedKeyword, $languageCode);
                $allKeywords = array_merge($allKeywords, $atpKeywords);
                
                Log::info('AnswerThePublic completed', [
                    'job_id' => $job->id,
                    'keywords_found' => count($atpKeywords),
                ]);
            } catch (\Throwable $e) {
                Log::error('AnswerThePublic failed', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->deduplicateKeywords($allKeywords);
    }

    protected function storeKeywords(KeywordResearchJob $job, array $keywords): array
    {
        $keywordIds = [];

        DB::transaction(function () use ($job, $keywords, &$keywordIds) {
            foreach ($keywords as $keywordDTO) {
                $keyword = Keyword::create([
                    'keyword_research_job_id' => $job->id,
                    'keyword' => $keywordDTO->keyword,
                    'source' => $keywordDTO->source,
                    'search_volume' => $keywordDTO->searchVolume,
                    'competition' => $keywordDTO->competition,
                    'cpc' => $keywordDTO->cpc,
                    'intent' => $keywordDTO->intent,
                    'intent_category' => $keywordDTO->intentCategory,
                    'intent_metadata' => $keywordDTO->intentMetadata,
                    'question_variations' => $keywordDTO->questionVariations,
                    'long_tail_versions' => $keywordDTO->longTailVersions,
                    'ai_visibility_score' => $keywordDTO->aiVisibilityScore,
                    'semantic_data' => $keywordDTO->semanticData,
                    'location' => null,
                    'language_code' => $job->language_code ?? 'en',
                    'geoTargetId' => $job->geoTargetId ?? 2840,
                ]);

                $keywordIds[] = $keyword->id;
            }
        });

        return $keywordIds;
    }

    protected function clusterKeywords(KeywordResearchJob $job, array $keywords): void
    {
        $numClusters = min(10, max(3, (int) (count($keywords) / 20)));
        
        $result = $this->clusteringService->clusterKeywords($keywords, $numClusters);
        
        DB::transaction(function () use ($job, $result) {
            $clusterModels = [];
            
            foreach ($result['clusters'] as $index => $clusterDTO) {
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

            foreach ($result['keyword_cluster_map'] as $keywordText => $clusterIndex) {
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
        $keywords = $job->keywords()->with('cluster')->get();
        $clusters = $job->clusters()->withCount('keywords')->get();

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

