<?php

namespace App\DTOs;

class CitationRequestDTO
{
    public function __construct(
        public readonly string $url,
        public readonly int $numQueries,
    ) {
    }

    public static function fromArray(array $data, int $defaultQueries): self
    {
        $url = $data['url'] ?? '';
        $numQueries = (int) ($data['num_queries'] ?? $defaultQueries);

        return new self(
            url: $url,
            numQueries: $numQueries,
        );
    }
}

