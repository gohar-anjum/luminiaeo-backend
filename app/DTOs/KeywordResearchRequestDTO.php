<?php

namespace App\DTOs;

class KeywordResearchRequestDTO
{
    public function __construct(
        public readonly string $query,
        public readonly ?int $projectId = null,
        public readonly string $languageCode = 'en',
        public readonly int $geoTargetId = 2840,
        public readonly ?int $maxKeywords = null,
        public readonly bool $enableGooglePlanner = true,
        public readonly bool $enableScraper = true,
        public readonly bool $enableClustering = true,
        public readonly bool $enableIntentScoring = true,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            query: $data['query'] ?? '',
            projectId: $data['project_id'] ?? null,
            languageCode: $data['language_code'] ?? 'en',
            geoTargetId: $data['geo_target_id'] ?? 2840,
            maxKeywords: $data['max_keywords'] ?? null,
            enableGooglePlanner: $data['enable_google_planner'] ?? true,
            enableScraper: $data['enable_scraper'] ?? true,
            enableClustering: $data['enable_clustering'] ?? true,
            enableIntentScoring: $data['enable_intent_scoring'] ?? true,
        );
    }

    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'project_id' => $this->projectId,
            'language_code' => $this->languageCode,
            'geo_target_id' => $this->geoTargetId,
            'max_keywords' => $this->maxKeywords,
            'enable_google_planner' => $this->enableGooglePlanner,
            'enable_scraper' => $this->enableScraper,
            'enable_clustering' => $this->enableClustering,
            'enable_intent_scoring' => $this->enableIntentScoring,
        ];
    }
}
