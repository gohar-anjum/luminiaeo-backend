<?php

namespace App\Services\Keyword;

use App\DTOs\KeywordDataDTO;
use App\Services\DataForSEO\DataForSEOService;
use App\Services\Serp\SerpService;
use Illuminate\Support\Facades\Log;

class KeywordDiscoveryService
{
    public function __construct(
        protected DataForSEOService $dataForSEOService,
        protected SerpService $serpService
    ) {
    }

    public function discoverKeywords(
        string $seedInput,
        string $languageCode = 'en',
        int $locationCode = 2840,
        array $options = []
    ): array {
        Log::info('Starting keyword discovery', [
            'seed_input' => $seedInput,
            'language_code' => $languageCode,
            'location_code' => $locationCode,
        ]);

        $allKeywords = [];
        $keywordMap = [];

        if ($options['use_dataforseo'] ?? true) {
            $dataForSEOKeywords = $this->discoverFromDataForSEO(
                $seedInput,
                $languageCode,
                $locationCode,
                $options
            );
            $allKeywords = array_merge($allKeywords, $dataForSEOKeywords);

            foreach ($dataForSEOKeywords as $keyword) {
                $keywordMap[strtolower(trim($keyword->keyword))] = $keyword;
            }
        }

        if ($options['use_serp'] ?? true) {
            $serpKeywords = $this->discoverFromSerp(
                $seedInput,
                $languageCode,
                $locationCode,
                $options
            );

            foreach ($serpKeywords as $keyword) {
                $key = strtolower(trim($keyword->keyword));
                if (!isset($keywordMap[$key])) {
                    $keywordMap[$key] = $keyword;
                    $allKeywords[] = $keyword;
                } else {

                    $existing = $keywordMap[$key];
                    $keywordMap[$key] = $this->mergeKeywordMetadata($existing, $keyword);
                }
            }
        }

        $enrichedKeywords = $this->enrichKeywords($allKeywords, $languageCode, $locationCode);

        Log::info('Keyword discovery completed', [
            'total_keywords' => count($enrichedKeywords),
            'sources' => [
                'dataforseo' => $options['use_dataforseo'] ?? true,
                'serp' => $options['use_serp'] ?? true,
            ],
        ]);

        return $enrichedKeywords;
    }

    protected function discoverFromDataForSEO(
        string $seedInput,
        string $languageCode,
        int $locationCode,
        array $options
    ): array {
        try {

            $seedKeyword = $this->extractSeedKeyword($seedInput);

            $keywords = [$seedKeyword];

            $searchVolumeData = $this->dataForSEOService->getSearchVolume(
                $keywords,
                $languageCode,
                $locationCode
            );

            $keywordDTOs = [];
            foreach ($searchVolumeData as $volumeData) {
                $keywordDTOs[] = new KeywordDataDTO(
                    keyword: $volumeData->keyword,
                    source: 'dataforseo',
                    searchVolume: $volumeData->searchVolume,
                    competition: $volumeData->competition,
                    cpc: $volumeData->cpc,
                    semanticData: [
                        'competition_index' => $volumeData->competitionIndex,
                        'keyword_info' => $volumeData->keywordInfo,
                    ],
                );
            }

            return $keywordDTOs;
        } catch (\Throwable $e) {
            Log::error('DataForSEO keyword discovery failed', [
                'error' => $e->getMessage(),
                'seed_input' => $seedInput,
            ]);
            return [];
        }
    }

    protected function discoverFromSerp(
        string $seedInput,
        string $languageCode,
        int $locationCode,
        array $options
    ): array {
        try {
            $seedKeyword = $this->extractSeedKeyword($seedInput);

            $serpData = $this->serpService->getKeywordData(
                [$seedKeyword],
                $languageCode,
                $locationCode,
                $options['serp_options'] ?? []
            );

            $keywordDTOs = [];
            foreach ($serpData as $serpKeyword) {
                $keywordDTOs[] = $serpKeyword->toKeywordDataDTO();
            }

            if (isset($serpData[0]->relatedKeywords) && is_array($serpData[0]->relatedKeywords)) {
                foreach ($serpData[0]->relatedKeywords as $relatedKeyword) {
                    $keywordDTOs[] = new KeywordDataDTO(
                        keyword: is_string($relatedKeyword) ? $relatedKeyword : ($relatedKeyword['keyword'] ?? ''),
                        source: 'serp_api',
                        semanticData: [
                            'related_to' => $seedKeyword,
                            'source' => 'serp_related',
                        ],
                    );
                }
            }

            return $keywordDTOs;
        } catch (\Throwable $e) {
            Log::error('Serp API keyword discovery failed', [
                'error' => $e->getMessage(),
                'seed_input' => $seedInput,
            ]);
            return [];
        }
    }

    protected function extractSeedKeyword(string $input): string
    {

        if (filter_var($input, FILTER_VALIDATE_URL)) {
            $domain = parse_url($input, PHP_URL_HOST);

            $domain = str_replace('www.', '', $domain);
            $parts = explode('.', $domain);
            return $parts[0] ?? $input;
        }

        if (strlen($input) > 50) {
            $words = explode(' ', $input);
            return implode(' ', array_slice($words, 0, 3));
        }

        return trim($input);
    }

    protected function mergeKeywordMetadata(KeywordDataDTO $existing, KeywordDataDTO $new): KeywordDataDTO
    {
        return new KeywordDataDTO(
            keyword: $existing->keyword,
            source: $existing->source . ',' . $new->source,
            searchVolume: $new->searchVolume ?? $existing->searchVolume,
            competition: $new->competition ?? $existing->competition,
            cpc: $new->cpc ?? $existing->cpc,
            semanticData: array_merge(
                $existing->semanticData ?? [],
                $new->semanticData ?? []
            ),
        );
    }

    protected function enrichKeywords(
        array $keywords,
        string $languageCode,
        int $locationCode
    ): array {

        $keywordsToEnrich = [];
        foreach ($keywords as $keyword) {
            if (!$keyword->searchVolume || !$keyword->competition) {
                $keywordsToEnrich[] = $keyword->keyword;
            }
        }

        if (!empty($keywordsToEnrich)) {
            try {
                $enrichmentData = $this->dataForSEOService->getSearchVolume(
                    array_slice($keywordsToEnrich, 0, 100),
                    $languageCode,
                    $locationCode
                );

                $enrichmentMap = [];
                foreach ($enrichmentData as $data) {
                    $enrichmentMap[strtolower($data->keyword)] = $data;
                }

                foreach ($keywords as $index => $keyword) {
                    $key = strtolower($keyword->keyword);
                    if (isset($enrichmentMap[$key])) {
                        $enrichment = $enrichmentMap[$key];
                        $keywords[$index] = new KeywordDataDTO(
                            keyword: $keyword->keyword,
                            source: $keyword->source,
                            searchVolume: $keyword->searchVolume ?? $enrichment->searchVolume,
                            competition: $keyword->competition ?? $enrichment->competition,
                            cpc: $keyword->cpc ?? $enrichment->cpc,
                            semanticData: array_merge(
                                $keyword->semanticData ?? [],
                                ['enriched' => true]
                            ),
                        );
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Keyword enrichment failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $keywords;
    }
}
