<?php

namespace App\DTOs;

class ClusterDataDTO
{
    public function __construct(
        public readonly string $topicName,
        public readonly ?string $description = null,
        public readonly ?array $suggestedArticleTitles = null,
        public readonly ?array $recommendedFaqQuestions = null,
        public readonly ?array $schemaSuggestions = null,
        public readonly ?float $aiVisibilityProjection = null,
        public readonly int $keywordCount = 0,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'topic_name' => $this->topicName,
            'description' => $this->description,
            'suggested_article_titles' => $this->suggestedArticleTitles,
            'recommended_faq_questions' => $this->recommendedFaqQuestions,
            'schema_suggestions' => $this->schemaSuggestions,
            'ai_visibility_projection' => $this->aiVisibilityProjection,
            'keyword_count' => $this->keywordCount,
        ], fn($value) => $value !== null);
    }
}
