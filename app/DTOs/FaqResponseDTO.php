<?php

namespace App\DTOs;

class FaqResponseDTO
{
    public function __construct(
        public readonly array $faqs,
        public readonly int $count,
        public readonly ?string $url = null,
        public readonly ?string $topic = null,
        public readonly bool $fromDatabase = false,
        public readonly int $apiCallsSaved = 0,
        public readonly ?string $createdAt = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'faqs' => $this->faqs,
            'count' => $this->count,
            'source' => [
                'url' => $this->url,
                'topic' => $this->topic,
            ],
            'metadata' => [
                'from_database' => $this->fromDatabase,
                'api_calls_saved' => $this->apiCallsSaved,
                'created_at' => $this->createdAt,
            ],
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            faqs: $data['faqs'] ?? [],
            count: $data['count'] ?? count($data['faqs'] ?? []),
            url: $data['source']['url'] ?? $data['url'] ?? null,
            topic: $data['source']['topic'] ?? $data['topic'] ?? null,
            fromDatabase: $data['metadata']['from_database'] ?? $data['from_database'] ?? false,
            apiCallsSaved: $data['metadata']['api_calls_saved'] ?? $data['api_calls_saved'] ?? 0,
            createdAt: $data['metadata']['created_at'] ?? $data['created_at'] ?? null,
        );
    }
}
