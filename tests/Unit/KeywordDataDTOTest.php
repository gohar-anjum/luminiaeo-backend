<?php

namespace Tests\Unit;

use App\DTOs\KeywordDataDTO;
use Tests\TestCase;

class KeywordDataDTOTest extends TestCase
{
    public function test_keyword_data_dto_creation(): void
    {
        $dto = new KeywordDataDTO(
            keyword: 'seo tools',
            source: 'test',
            searchVolume: 1000,
            competition: 0.5,
            cpc: 1.5,
        );

        $this->assertEquals('seo tools', $dto->keyword);
        $this->assertEquals('test', $dto->source);
        $this->assertEquals(1000, $dto->searchVolume);
        $this->assertEquals(0.5, $dto->competition);
        $this->assertEquals(1.5, $dto->cpc);
    }

    public function test_keyword_data_dto_to_array(): void
    {
        $dto = new KeywordDataDTO(
            keyword: 'test',
            source: 'test',
            searchVolume: 1000,
            competition: 0.5,
            cpc: 1.5,
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('test', $array['keyword']);
        $this->assertEquals(1000, $array['search_volume']);
    }
}

