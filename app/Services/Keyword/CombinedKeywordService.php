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

        // Check if target is a valid domain/URL (required for keywords_for_site endpoint)
        // keywords_for_site endpoint requires a domain, not a keyword string
        $isDomain = $this->isValidDomain($target);

        if ($isDomain) {
            // Only call getKeywordsForSite if we have a valid domain/URL
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
        } else {
            // Target is a keyword string, not a domain
            // Use keywords_for_keywords endpoint to get keyword suggestions
            Log::info('Target is a keyword string, using DataForSEO keywords_for_keywords endpoint', [
                'target' => $target,
            ]);
            
            try {
                $dataForSEOKeywordIdeas = $this->dataForSEOService->getKeywordIdeas(
                    $target,
                    $languageCode,
                    $locationCode,
                    $limit ? (int) ($limit * 0.7) : null
                );

                foreach ($dataForSEOKeywordIdeas as $keywordDTO) {
                    $keywordLower = strtolower(trim($keywordDTO->keyword));
                    if (!isset($keywordMap[$keywordLower])) {
                        $allKeywords[] = $keywordDTO;
                        $keywordMap[$keywordLower] = true;
                    }
                }

                Log::info('DataForSEO keyword ideas collected (keywords_for_keywords)', [
                    'count' => count($dataForSEOKeywordIdeas),
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to get keyword ideas from DataForSEO', [
                    'error' => $e->getMessage(),
                ]);
            }
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
            'dataforseo_keywords_for_site_count' => count(array_filter($allKeywords, fn($k) => $k->source === 'dataforseo_keywords_for_site')),
            'dataforseo_keyword_planner_count' => count(array_filter($allKeywords, fn($k) => $k->source === 'dataforseo_keyword_planner')),
            'alsoasked_count' => count(array_filter($allKeywords, fn($k) => $k->source === 'alsoasked')),
        ]);

        return $allKeywords;
    }

    protected function mapLocationToRegion(int $locationCode): string
    {
        $locationCodeService = app(LocationCodeService::class);
        return $locationCodeService->mapLocationCodeToRegion($locationCode, 'us');
    }

    /**
     * Check if the target string is a valid domain/URL
     * keywords_for_site endpoint requires a domain, not a keyword string
     */
    protected function isValidDomain(string $target): bool
    {
        // Remove protocol if present
        $target = preg_replace('/^https?:\/\//i', '', trim($target));
        $target = rtrim($target, '/');

        // Check if it looks like a domain (contains at least one dot and valid domain characters)
        // Simple validation: contains a dot and has valid domain characters
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*\.[a-z]{2,}$/i', $target)) {
            return true;
        }

        // Check if it's an IP address
        if (filter_var($target, FILTER_VALIDATE_IP)) {
            return true;
        }

        return false;
    }
}
