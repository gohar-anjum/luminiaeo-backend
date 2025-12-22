<?php

namespace App\DTOs;

class KeywordsForSiteDTO
{
    public function __construct(
        public string $keyword,
        public int $locationCode,
        public ?string $languageCode = null,
        public ?bool $searchPartners = null,
        public ?string $competition = null,
        public ?int $competitionIndex = null,
        public ?int $searchVolume = null,
        public ?float $lowTopOfPageBid = null,
        public ?float $highTopOfPageBid = null,
        public ?float $cpc = null,
        public ?array $monthlySearches = null,
        public ?array $keywordAnnotations = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            keyword: $data['keyword'] ?? '',
            locationCode: $data['location_code'] ?? 2840,
            languageCode: $data['language_code'] ?? null,
            searchPartners: $data['search_partners'] ?? null,
            competition: $data['competition'] ?? null,
            competitionIndex: $data['competition_index'] ?? null,
            searchVolume: $data['search_volume'] ?? null,
            lowTopOfPageBid: isset($data['low_top_of_page_bid']) ? (float) $data['low_top_of_page_bid'] : null,
            highTopOfPageBid: isset($data['high_top_of_page_bid']) ? (float) $data['high_top_of_page_bid'] : null,
            cpc: isset($data['cpc']) ? (float) $data['cpc'] : null,
            monthlySearches: $data['monthly_searches'] ?? null,
            keywordAnnotations: $data['keyword_annotations'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'keyword' => $this->keyword,
            'location_code' => $this->locationCode,
            'language_code' => $this->languageCode,
            'search_partners' => $this->searchPartners,
            'competition' => $this->competition,
            'competition_index' => $this->competitionIndex,
            'search_volume' => $this->searchVolume,
            'low_top_of_page_bid' => $this->lowTopOfPageBid,
            'high_top_of_page_bid' => $this->highTopOfPageBid,
            'cpc' => $this->cpc,
            'monthly_searches' => $this->monthlySearches,
            'keyword_annotations' => $this->keywordAnnotations,
        ], fn($value) => $value !== null);
    }

    public function toKeywordDataDTO(): KeywordDataDTO
    {

        $competitionValue = match ($this->competition) {
            'LOW' => 0.0,
            'MEDIUM' => 0.5,
            'HIGH' => 1.0,
            default => null,
        };

        return new KeywordDataDTO(
            keyword: $this->keyword,
            source: 'dataforseo_keywords_for_site',
            searchVolume: $this->searchVolume,
            competition: $competitionValue,
            cpc: $this->cpc,
        );
    }
}
