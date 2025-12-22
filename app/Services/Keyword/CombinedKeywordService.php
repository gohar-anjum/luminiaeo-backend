<?php

namespace App\Services\Keyword;

use App\DTOs\KeywordDataDTO;
use App\Interfaces\KeywordCacheRepositoryInterface;
use App\Services\DataForSEO\DataForSEOService;
use App\Services\FAQ\AlsoAskedService;
use Illuminate\Support\Facades\Log;

class CombinedKeywordService
{
    protected DataForSEOService $dataForSEOService;
    protected AlsoAskedService $alsoAskedService;
    protected KeywordCacheRepositoryInterface $cacheRepository;

    public function __construct(
        DataForSEOService $dataForSEOService,
        AlsoAskedService $alsoAskedService,
        KeywordCacheRepositoryInterface $cacheRepository
    ) {
        $this->dataForSEOService = $dataForSEOService;
        $this->alsoAskedService = $alsoAskedService;
        $this->cacheRepository = $cacheRepository;
    }

    public function getCombinedKeywords(
        string $target,
        int $locationCode = 2840,
        string $languageCode = 'en',
        ?int $limit = null
    ): array {
        $allKeywords = [];
        $keywordMap = [];

        Log::info('Starting combined keyword collection', [
            'target' => $target,
            'location_code' => $locationCode,
            'language_code' => $languageCode,
        ]);

        try {
            $dataForSEOKeywords = $this->dataForSEOService->getKeywordsForSite(
                $target,
                $locationCode,
                $languageCode,
                true,
                null,
                null,
                false,
                null,
                $limit ? (int) ($limit * 0.7) : null
            );

            foreach ($dataForSEOKeywords as $dto) {
                $keywordLower = strtolower(trim($dto->keyword));
                if (!isset($keywordMap[$keywordLower])) {
                    $keywordDTO = $dto->toKeywordDataDTO();
                    $allKeywords[] = $keywordDTO;
                    $keywordMap[$keywordLower] = true;
                }
            }

            Log::info('DataForSEO keywords collected', [
                'count' => count($dataForSEOKeywords),
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to get keywords from DataForSEO', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if ($this->alsoAskedService->isAvailable()) {
                $alsoAskedKeywords = $this->alsoAskedService->getKeywords(
                    $target,
                    $languageCode,
                    $this->mapLocationToRegion($locationCode),
                    2,
                    false
                );

                $alsoAskedLimit = $limit ? (int) ($limit * 0.3) : null;
                if ($alsoAskedLimit !== null && count($alsoAskedKeywords) > $alsoAskedLimit) {
                    $alsoAskedKeywords = array_slice($alsoAskedKeywords, 0, $alsoAskedLimit);
                }

                foreach ($alsoAskedKeywords as $keywordText) {
                    $keywordLower = strtolower(trim($keywordText));
                    if (!isset($keywordMap[$keywordLower]) && strlen($keywordText) >= 3) {
                        $keywordDTO = new KeywordDataDTO(
                            keyword: $keywordText,
                            source: 'alsoasked',
                            searchVolume: null,
                            competition: null,
                            cpc: null,
                        );
                        $allKeywords[] = $keywordDTO;
                        $keywordMap[$keywordLower] = true;
                    }
                }

                Log::info('AlsoAsked keywords collected', [
                    'count' => count($alsoAskedKeywords),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get keywords from AlsoAsked', [
                'error' => $e->getMessage(),
            ]);
        }

        if ($limit !== null && count($allKeywords) > $limit) {
            $allKeywords = array_slice($allKeywords, 0, $limit);
        }

        Log::info('Combined keyword collection completed', [
            'total_keywords' => count($allKeywords),
            'dataforseo_count' => count(array_filter($allKeywords, fn($k) => $k->source === 'dataforseo_keywords_for_site')),
            'alsoasked_count' => count(array_filter($allKeywords, fn($k) => $k->source === 'alsoasked')),
        ]);

        return $allKeywords;
    }

    protected function mapLocationToRegion(int $locationCode): string
    {
        $mapping = [
            2840 => 'us',
            2826 => 'uk',
            2036 => 'au',
            2124 => 'ca',
            2752 => 'nz',
        ];

        return $mapping[$locationCode] ?? 'us';
    }
}
