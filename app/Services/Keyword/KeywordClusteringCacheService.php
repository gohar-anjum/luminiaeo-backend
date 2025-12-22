<?php

namespace App\Services\Keyword;

use App\DTOs\ClusterDataDTO;
use App\DTOs\KeywordDataDTO;
use App\Interfaces\KeywordCacheRepositoryInterface;
use App\Services\Keyword\SemanticClusteringService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class KeywordClusteringCacheService
{
    public function __construct(
        protected KeywordCacheRepositoryInterface $cacheRepository,
        protected SemanticClusteringService $clusteringService,
        protected KeywordCacheService $cacheService
    ) {
    }

    public function clusterAndCache(
        array $keywords,
        string $languageCode = 'en',
        int $locationCode = 2840,
        int $numClusters = 5
    ): array {
        if (empty($keywords)) {
            return ['clusters' => [], 'keyword_cluster_map' => []];
        }

        $clusteringResult = $this->clusteringService->clusterKeywords($keywords, $numClusters);

        $clusterId = $this->generateClusterId($keywords, $languageCode, $locationCode);

        $this->updateCacheWithClusters(
            $keywords,
            $clusteringResult['keyword_cluster_map'],
            $clusteringResult['clusters'],
            $clusterId,
            $languageCode,
            $locationCode
        );

        Log::info('Clustered and cached keywords', [
            'keyword_count' => count($keywords),
            'cluster_count' => count($clusteringResult['clusters']),
            'cluster_id' => $clusterId,
        ]);

        return $clusteringResult;
    }

    public function getClusteredKeywords(string $clusterId): array
    {
        $cachedKeywords = $this->cacheRepository->findByCluster($clusterId);

        if ($cachedKeywords->isEmpty()) {
            return [];
        }

        return $cachedKeywords->map(function ($cache) {
            return $this->cacheService->convertCacheToDTO($cache);
        })->toArray();
    }

    public function refreshClusterCache(int $batchSize = 50): int
    {
        $expiringSoon = $this->cacheRepository->getExpiringSoon(7);

        if ($expiringSoon->isEmpty()) {
            return 0;
        }

        $clusters = $expiringSoon->groupBy('cluster_id');

        $refreshed = 0;

        foreach ($clusters as $clusterId => $keywords) {
            if (!$clusterId) {
                continue;
            }

            try {
                $keywordDTOs = $keywords->map(function ($cache) {
                    return $this->cacheService->convertCacheToDTO($cache);
                })->toArray();

                $languageCode = $keywords->first()->language_code ?? 'en';
                $locationCode = $keywords->first()->location_code ?? 2840;

                $this->clusterAndCache(
                    $keywordDTOs,
                    $languageCode,
                    $locationCode,
                    min(10, max(3, (int) (count($keywordDTOs) / 20)))
                );

                $refreshed++;

                Log::info('Refreshed cluster cache', [
                    'cluster_id' => $clusterId,
                    'keyword_count' => count($keywordDTOs),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to refresh cluster cache', [
                    'cluster_id' => $clusterId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $refreshed;
    }

    protected function updateCacheWithClusters(
        array $keywords,
        array $keywordClusterMap,
        array $clusters,
        string $clusterId,
        string $languageCode,
        int $locationCode
    ): void {
        $clusterDataMap = [];

        foreach ($clusters as $index => $clusterDTO) {
            $clusterDataMap[$index] = [
                'topic_name' => $clusterDTO->topicName,
                'description' => $clusterDTO->description,
                'suggested_article_titles' => $clusterDTO->suggestedArticleTitles,
                'recommended_faq_questions' => $clusterDTO->recommendedFaqQuestions,
                'schema_suggestions' => $clusterDTO->schemaSuggestions,
                'ai_visibility_projection' => $clusterDTO->aiVisibilityProjection,
                'keyword_count' => $clusterDTO->keywordCount,
            ];
        }

        foreach ($keywords as $keywordDTO) {
            $keyword = $keywordDTO->keyword;
            $clusterIndex = $keywordClusterMap[$keyword] ?? null;

            if ($clusterIndex !== null) {
                $cache = $this->cacheRepository->find($keyword, $languageCode, $locationCode);

                if ($cache) {
                    $cache->update([
                        'cluster_id' => $clusterId,
                        'cluster_data' => $clusterDataMap[$clusterIndex] ?? null,
                    ]);
                } else {

                    $this->cacheRepository->create([
                        'keyword' => $keyword,
                        'language_code' => $languageCode,
                        'location_code' => $locationCode,
                        'search_volume' => $keywordDTO->searchVolume,
                        'competition' => $keywordDTO->competition,
                        'cpc' => $keywordDTO->cpc,
                        'cluster_id' => $clusterId,
                        'cluster_data' => $clusterDataMap[$clusterIndex] ?? null,
                        'source' => $keywordDTO->source ?? 'cache',
                        'metadata' => [
                            'semantic_data' => $keywordDTO->semanticData,
                        ],
                    ]);
                }
            }
        }
    }

    protected function generateClusterId(array $keywords, string $languageCode, int $locationCode): string
    {
        $keywordHashes = array_map(function ($keyword) {
            return md5($keyword->keyword);
        }, $keywords);

        sort($keywordHashes);

        $hash = md5(implode('', $keywordHashes) . $languageCode . $locationCode);

        return "cluster_{$hash}";
    }
}
