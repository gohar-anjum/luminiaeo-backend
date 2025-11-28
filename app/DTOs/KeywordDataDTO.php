<?php

namespace App\DTOs;

class KeywordDataDTO
{
    public function __construct(
        public readonly string $keyword,
        public readonly string $source,
        public readonly ?int $searchVolume = null,
        public readonly ?float $competition = null,
        public readonly ?float $cpc = null,
        public readonly ?string $intent = null,
        public readonly ?string $intentCategory = null,
        public readonly ?array $intentMetadata = null,
        public readonly ?array $questionVariations = null,
        public readonly ?array $longTailVersions = null,
        public readonly ?float $aiVisibilityScore = null,
        public readonly ?array $semanticData = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'keyword' => $this->keyword,
            'source' => $this->source,
            'search_volume' => $this->searchVolume,
            'competition' => $this->competition,
            'cpc' => $this->cpc,
            'intent' => $this->intent,
            'intent_category' => $this->intentCategory,
            'intent_metadata' => $this->intentMetadata,
            'question_variations' => $this->questionVariations,
            'long_tail_versions' => $this->longTailVersions,
            'ai_visibility_score' => $this->aiVisibilityScore,
            'semantic_data' => $this->semanticData,
        ], fn($value) => $value !== null);
    }
}

