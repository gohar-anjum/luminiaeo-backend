<?php

namespace App\DTOs;

class CitationQueryResultDTO
{
    public function __construct(
        public readonly int $index,
        public readonly string $query,
        public readonly ?array $gpt,
        public readonly ?array $gemini,
        public readonly array $topCompetitors = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'gpt' => $this->gpt,
            'gemini' => $this->gemini,
            'top_competitors' => $this->topCompetitors,
        ];
    }
}
