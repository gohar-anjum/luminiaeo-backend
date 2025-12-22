<?php

namespace Tests\Unit;

use App\DTOs\SearchVolumeDTO;
use Tests\TestCase;

class SearchVolumeDTOTest extends TestCase
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
        ];

        $dto = SearchVolumeDTO::fromArray($data);

        $this->assertEquals('seo tools', $dto->keyword);
        $this->assertEquals(1000, $dto->searchVolume);
        $this->assertEquals(0.5, $dto->competition);
        $this->assertEquals(1.5, $dto->cpc);
    }

    public function test_to_array_converts_dto(): void
    {
        $dto = new SearchVolumeDTO(
            keyword: 'test',
            locationCode: 2840,
            languageCode: 'en',
            searchVolume: 1000,
            competition: 0.5,
            cpc: 1.5
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('test', $array['keyword']);
        $this->assertEquals(1000, $array['search_volume']);
    }
}

