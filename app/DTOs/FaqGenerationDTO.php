<?php

namespace App\DTOs;

class FaqGenerationDTO
{
    public function __construct(
        public readonly string $input,
        public readonly array $options = [],
        public readonly ?int $userId = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'input' => $this->input,
            'options' => $this->options,
            'user_id' => $this->userId,
        ], fn($value) => $value !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            input: $data['input'] ?? '',
            options: $data['options'] ?? [],
            userId: $data['user_id'] ?? $data['userId'] ?? null,
        );
    }

    public function isUrl(): bool
    {
        $input = trim($this->input);

        if (filter_var($input, FILTER_VALIDATE_URL)) {
            return true;
        }

        if (preg_match('/^https?:\/\//', $input)) {
            return true;
        }

        if (preg_match('/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}(\/.*)?$/i', $input)) {
            return true;
        }

        return false;
    }

    public function getUrl(): ?string
    {
        return $this->isUrl() ? $this->input : null;
    }

    public function getTopic(): ?string
    {
        return $this->isUrl() ? null : $this->input;
    }
}
