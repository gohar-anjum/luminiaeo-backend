<?php

namespace App\Services\Keyword;

use App\DTOs\KeywordDataDTO;
use App\DTOs\SerpKeywordDataDTO;
use App\Interfaces\KeywordCacheRepositoryInterface;
use App\Services\Serp\SerpService;
use App\Services\DataForSEO\DataForSEOService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class KeywordCacheService
{
    public function __construct(
        protected KeywordCacheRepositoryInterface $cacheRepository,
        protected SerpService $serpService,
        protected DataForSEOService $dataForSEOService
    ) {
    }

    public function getKeywordData(
        array $keywords,
        string $languageCode = 'en',
        int $locationCode = 2840,
        string $source = 'serp_api'
    ): array {
        $results = [];
        $keywordsToFetch = [];

        foreach ($keywords as $keyword) {
            $cached = $this->cacheRepository->findValid($keyword, $languageCode, $locationCode);

            if ($cached && $cached->isValid()) {
                $results[] = $this->convertCacheToDTO($cached);
                Log::debug('Cache hit for keyword', [
                    'keyword' => $keyword,
                    'cached_at' => $cached->cached_at,
                    'expires_at' => $cached->expires_at,
                ]);
            } else {
                $keywordsToFetch[] = $keyword;
            }
        }

        if (!empty($keywordsToFetch)) {
            $fetchedData = $this->fetchFromApi($keywordsToFetch, $languageCode, $locationCode, $source);

            $this->cacheKeywordData($fetchedData, $languageCode, $locationCode, $source);

            $results = array_merge($results, $fetchedData);
        }

        return $results;
    }

    public function cacheKeywordData(
        array $keywordData,
        string $languageCode = 'en',
        int $locationCode = 2840,
        string $source = 'serp_api'
    ): void {
        $cacheData = [];

        foreach ($keywordData as $dto) {
            if ($dto instanceof SerpKeywordDataDTO) {
                $cacheData[] = [
                    'keyword' => $dto->keyword,
                    'language_code' => $languageCode,
                    'location_code' => $locationCode,
                    'search_volume' => $dto->searchVolume,
                    'competition' => $dto->competition,
                    'cpc' => $dto->cpc,
                    'difficulty' => $dto->difficulty,
                    'serp_features' => $dto->serpFeatures,
                    'related_keywords' => $dto->relatedKeywords,
                    'trends' => $dto->trends,
                    'source' => $source,
                    'metadata' => [
                        'cached_at' => now()->toIso8601String(),
                    ],
                ];
            } elseif ($dto instanceof KeywordDataDTO) {
                $cacheData[] = [
                    'keyword' => $dto->keyword,
                    'language_code' => $languageCode,
                    'location_code' => $locationCode,
                    'search_volume' => $dto->searchVolume,
                    'competition' => $dto->competition,
                    'cpc' => $dto->cpc,
                    'source' => $source,
                    'metadata' => [
                        'cached_at' => now()->toIso8601String(),
                        'semantic_data' => $dto->semanticData,
                    ],
                ];
            }
        }

        if (!empty($cacheData)) {
            $this->cacheRepository->bulkUpdate($cacheData);

            Log::info('Cached keyword data', [
                'count' => count($cacheData),
                'source' => $source,
            ]);
        }
    }

    public function refreshExpiredCache(int $batchSize = 100): int
    {
        $expired = $this->cacheRepository->getExpiringSoon(0);

        if ($expired->isEmpty()) {
            return 0;
        }

        $refreshed = 0;
        $batches = $expired->chunk($batchSize);

        foreach ($batches as $batch) {
            $keywords = $batch->pluck('keyword')->toArray();
            $languageCode = $batch->first()->language_code ?? 'en';
            $locationCode = $batch->first()->location_code ?? 2840;
            $source = $batch->first()->source ?? 'serp_api';

            try {
                $fetchedData = $this->fetchFromApi($keywords, $languageCode, $locationCode, $source);
                $this->cacheKeywordData($fetchedData, $languageCode, $locationCode, $source);
                $refreshed += count($fetchedData);

                Log::info('Refreshed expired cache batch', [
                    'batch_size' => count($keywords),
                    'refreshed' => count($fetchedData),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to refresh cache batch', [
                    'error' => $e->getMessage(),
                    'keywords' => $keywords,
                ]);
            }
        }

        return $refreshed;
    }

    public function cleanupExpiredCache(): int
    {
        return $this->cacheRepository->deleteExpired();
    }

    protected function fetchFromApi(
        array $keywords,
        string $languageCode,
        int $locationCode,
        string $source
    ): array {
        if ($source === 'serp_api') {
            $results = $this->serpService->getKeywordData($keywords, $languageCode, $locationCode);
            return array_map(function ($dto) {
                return $dto->toKeywordDataDTO();
            }, $results);
        } elseif ($source === 'dataforseo') {
            return $this->dataForSEOService->getSearchVolume($keywords, $languageCode, $locationCode);
        }

        throw new \InvalidArgumentException("Unknown source: {$source}");
    }

    public function convertCacheToDTO($cache): KeywordDataDTO
    {
        return new KeywordDataDTO(
            keyword: $cache->keyword,
            source: $cache->source ?? 'cache',
            searchVolume: $cache->search_volume,
            competition: $cache->competition,
            cpc: $cache->cpc,
            semanticData: array_merge(
                $cache->metadata ?? [],
                [
                    'difficulty' => $cache->difficulty,
                    'serp_features' => $cache->serp_features,
                    'related_keywords' => $cache->related_keywords,
                    'trends' => $cache->trends,
                    'cluster_id' => $cache->cluster_id,
                    'cluster_data' => $cache->cluster_data,
                ]
            ),
        );
    }
}
