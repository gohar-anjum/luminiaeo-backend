<?php

namespace Tests\Unit;

use App\DTOs\KeywordsForSiteDTO;
use Tests\TestCase;

class KeywordsForSiteDTOTest extends TestCase
{
    public function test_from_array_creates_dto(): void
    {
        $data = [
            'keyword' => 'seo tools',
            'location_code' => 2840,
            'language_code' => 'en',
            'search_volume' => 1000,
            'competition' => 0.5,
            'cpc' => 1.5,
            'monthly_searches' => [
                ['year' => 2024, 'month' => 1, 'search_volume' => 1000],
            ],
            'competition_index' => 50,
            'target' => 'example.com',
        ];

        $dto = KeywordsForSiteDTO::fromArray($data);

        $this->assertEquals('seo tools', $dto->keyword);
        $this->assertEquals(1000, $dto->searchVolume);
        $this->assertEquals(0.5, $dto->competition);
        $this->assertEquals(1.5, $dto->cpc);
    }

    public function test_to_array_converts_dto(): void
    {
        $dto = new KeywordsForSiteDTO(
            keyword: 'test',
            locationCode: 2840,
            languageCode: 'en',
            competition: 0.5,
            searchVolume: 1000,
            cpc: 1.5,
            monthlySearches: [],
            competitionIndex: 50,
            target: 'example.com'
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('test', $array['keyword']);
        $this->assertEquals(1000, $array['search_volume']);
    }

    public function test_to_keyword_data_dto_converts(): void
    {
        $dto = new KeywordsForSiteDTO(
            keyword: 'test',
            locationCode: 2840,
            languageCode: 'en',
            competition: 0.5,
            searchVolume: 1000,
            cpc: 1.5,
            monthlySearches: [],
            competitionIndex: 50,
            target: 'example.com'
        );

        $keywordDTO = $dto->toKeywordDataDTO();

        $this->assertEquals('test', $keywordDTO->keyword);
        $this->assertEquals(1000, $keywordDTO->searchVolume);
        $this->assertEquals('dataforseo_keywords_for_site', $keywordDTO->source);
    }
}

