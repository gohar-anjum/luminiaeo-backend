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

            } catch (\Exception $e) {
            }
        } else {
            
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

            } catch (\Exception $e) {
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

            }
        } catch (\Exception $e) {
        }

        if ($limit !== null && count($allKeywords) > $limit) {
            $allKeywords = array_slice($allKeywords, 0, $limit);
        }

        return $allKeywords;
    }

    protected function mapLocationToRegion(int $locationCode): string
    {
        $locationCodeService = app(LocationCodeService::class);
        return $locationCodeService->mapLocationCodeToRegion($locationCode, 'us');
    }

    protected function isValidDomain(string $target): bool
    {
        $target = preg_replace('/^https?:\/\//i', '', trim($target));
        $target = rtrim($target, '/');

        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*\.[a-z]{2,}$/i', $target)) {
            return true;
        }

        if (filter_var($target, FILTER_VALIDATE_IP)) {
            return true;
        }

        return false;
    }
}
