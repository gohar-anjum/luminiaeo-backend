<?php

namespace App\DTOs;

class FaqDataDTO
{
    public function __construct(
        public readonly string $question,
        public readonly string $answer,
    ) {
    }

    public function toArray(): array
    {
        return [
            'question' => $this->question,
            'answer' => $this->answer,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            question: $data['question'] ?? $data['q'] ?? '',
            answer: $data['answer'] ?? $data['a'] ?? '',
        );
    }
}
