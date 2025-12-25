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
        // Convert competition string to float (HIGH=1.0, MEDIUM=0.5, LOW=0.0)
        $competition = null;
        if (isset($data['competition'])) {
            $compStr = strtoupper((string) $data['competition']);
            $competition = match ($compStr) {
                'HIGH' => 1.0,
                'MEDIUM' => 0.5,
                'LOW' => 0.0,
                default => null,
            };
        }

        // Calculate CPC from low/high bids if cpc is not provided
        $cpc = $data['cpc'] ?? null;
        if ($cpc === null && isset($data['low_top_of_page_bid']) && isset($data['high_top_of_page_bid'])) {
            $cpc = ($data['low_top_of_page_bid'] + $data['high_top_of_page_bid']) / 2;
        }

        return new self(
            keyword: $data['keyword'] ?? '',
            searchVolume: $data['search_volume'] ?? null,
            competition: $competition,
            cpc: $cpc,
            competitionIndex: $data['competition_index'] ?? null,
            keywordInfo: [
                'monthly_searches' => $data['monthly_searches'] ?? null,
                'low_top_of_page_bid' => $data['low_top_of_page_bid'] ?? null,
                'high_top_of_page_bid' => $data['high_top_of_page_bid'] ?? null,
                'search_partners' => $data['search_partners'] ?? null,
                'spell' => $data['spell'] ?? null,
            ],
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
