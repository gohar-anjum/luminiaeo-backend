<?php

namespace App\DTOs;

class SerpKeywordDataDTO
{
    public function __construct(
        public readonly string $keyword,
        public readonly ?int $searchVolume = null,
        public readonly ?float $competition = null,
        public readonly ?float $cpc = null,
        public readonly ?int $difficulty = null,
        public readonly ?array $serpFeatures = null,
        public readonly ?array $relatedKeywords = null,
        public readonly ?array $trends = null,
        public readonly ?string $languageCode = null,
        public readonly ?int $locationCode = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            keyword: $data['keyword'] ?? '',
            searchVolume: $data['search_volume'] ?? $data['searchVolume'] ?? null,
            competition: $data['competition'] ?? null,
            cpc: $data['cpc'] ?? null,
            difficulty: $data['difficulty'] ?? $data['keyword_difficulty'] ?? null,
            serpFeatures: $data['serp_features'] ?? $data['serpFeatures'] ?? null,
            relatedKeywords: $data['related_keywords'] ?? $data['relatedKeywords'] ?? null,
            trends: $data['trends'] ?? null,
            languageCode: $data['language_code'] ?? $data['languageCode'] ?? null,
            locationCode: $data['location_code'] ?? $data['locationCode'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'keyword' => $this->keyword,
            'search_volume' => $this->searchVolume,
            'competition' => $this->competition,
            'cpc' => $this->cpc,
            'difficulty' => $this->difficulty,
            'serp_features' => $this->serpFeatures,
            'related_keywords' => $this->relatedKeywords,
            'trends' => $this->trends,
            'language_code' => $this->languageCode,
            'location_code' => $this->locationCode,
        ], fn($value) => $value !== null);
    }

    public function toKeywordDataDTO(): KeywordDataDTO
    {
        return new KeywordDataDTO(
            keyword: $this->keyword,
            source: 'serp_api',
            searchVolume: $this->searchVolume,
            competition: $this->competition,
            cpc: $this->cpc,
            semanticData: [
                'difficulty' => $this->difficulty,
                'serp_features' => $this->serpFeatures,
                'related_keywords' => $this->relatedKeywords,
                'trends' => $this->trends,
            ],
        );
    }
}
