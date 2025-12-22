<?php

namespace App\DTOs;

class SearchVolumeDTO
{
    public function __construct(
        public string $keyword,
        public ?int $searchVolume = null,
        public ?float $competition = null,
        public ?float $cpc = null,
        public ?string $competitionIndex = null,
        public ?array $keywordInfo = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            keyword: $data['keyword'] ?? '',
            searchVolume: $data['search_volume'] ?? null,
            competition: $data['competition'] ?? null,
            cpc: $data['cpc'] ?? null,
            competitionIndex: $data['competition_index'] ?? null,
            keywordInfo: $data['keyword_info'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'keyword' => $this->keyword,
            'search_volume' => $this->searchVolume,
            'competition' => $this->competition,
            'cpc' => $this->cpc,
            'competition_index' => $this->competitionIndex,
            'keyword_info' => $this->keywordInfo,
        ];
    }
}
