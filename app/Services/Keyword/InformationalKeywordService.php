<?php

namespace App\Services\Keyword;

use App\DTOs\KeywordDataDTO;
use App\Exceptions\DataForSEOException;
use App\Services\DataForSEO\DataForSEOService;
use Illuminate\Support\Facades\Log;

class InformationalKeywordService
{
    public const DEFAULT_TOP_N = 100;

    public const DEFAULT_LABS_LIMIT = 1000;

    public function __construct(
        protected DataForSEOService $dataForSEOService,
        protected KeywordIntentService $keywordIntentService
    ) {
    }

    /**
     * Fetch up to 1000 informational keyword ideas from DataForSEO Labs, then rank them
     * via the keyword-intent microservice and return the top 100.
     *
     * @param  string|array<int, string>  $seedKeywords  One or more seed keywords (e.g. ["taxi"])
     * @param  array{location_code?: int, language_code?: string, limit?: int, top_n?: int}  $options
     * @return array<int, KeywordDataDTO>  Top N keywords with informational_score in semanticData
     */
    public function getTopInformationalKeywords(
        string|array $seedKeywords,
        array $options = []
    ): array {
        $locationCode = (int) ($options['location_code'] ?? 2840);
        $languageCode = (string) ($options['language_code'] ?? 'en');
        $labsLimit = (int) ($options['limit'] ?? self::DEFAULT_LABS_LIMIT);
        $topN = (int) ($options['top_n'] ?? self::DEFAULT_TOP_N);

        $seedArray = is_array($seedKeywords) ? $seedKeywords : [trim((string) $seedKeywords)];
        $seedArray = array_values(array_filter(array_map('trim', $seedArray), fn ($s) => $s !== ''));

        if (empty($seedArray)) {
            return [];
        }

        $informationalFilter = [
            ['search_intent_info.main_intent', '=', 'informational'],
        ];

        try {
            $allDto = $this->dataForSEOService->getKeywordIdeasFromLabs(
                $seedArray,
                $languageCode,
                $locationCode,
                $labsLimit,
                true,
                $informationalFilter
            );
        } catch (DataForSEOException $e) {
            Log::error('DataForSEO Labs keyword ideas failed in informational flow', [
                'error' => $e->getMessage(),
                'seeds' => $seedArray,
            ]);
            throw $e;
        }

        if (empty($allDto)) {
            return [];
        }

        $keywordStrings = array_map(fn (KeywordDataDTO $dto) => $dto->keyword, $allDto);
        $ranked = $this->keywordIntentService->rankByInformationalIntent($keywordStrings);

        if (empty($ranked)) {
            return array_slice($allDto, 0, $topN);
        }

        $scoreByKeyword = [];
        foreach ($ranked as $item) {
            $k = $item['keyword'] ?? '';
            $s = $item['informational_score'] ?? 0.0;
            if ($k !== '') {
                $scoreByKeyword[$k] = (float) $s;
            }
        }

        $dtoByKeyword = [];
        foreach ($allDto as $dto) {
            $key = $dto->keyword;
            if (!isset($dtoByKeyword[$key])) {
                $dtoByKeyword[$key] = $dto;
            }
        }

        $result = [];
        $seen = [];
        foreach ($ranked as $item) {
            if (count($result) >= $topN) {
                break;
            }
            $k = $item['keyword'] ?? '';
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $dto = $dtoByKeyword[$k] ?? null;
            $score = $scoreByKeyword[$k] ?? 0.0;
            if ($dto !== null) {
                $result[] = new KeywordDataDTO(
                    keyword: $dto->keyword,
                    source: $dto->source,
                    searchVolume: $dto->searchVolume,
                    competition: $dto->competition,
                    cpc: $dto->cpc,
                    intent: $dto->intent,
                    intentCategory: $dto->intentCategory ?? 'informational',
                    intentMetadata: $dto->intentMetadata,
                    longTailVersions: $dto->longTailVersions,
                    aiVisibilityScore: $score,
                    semanticData: array_merge($dto->semanticData ?? [], [
                        'informational_score' => $score,
                    ]),
                );
            }
        }

        return $result;
    }
}
