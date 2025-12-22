<?php

namespace Tests\Unit;

use App\DTOs\KeywordDataDTO;
use App\Services\Keyword\KeywordDataValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordDataValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected KeywordDataValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new KeywordDataValidationService();
    }

    public function test_validate_keyword_validates_structure(): void
    {
        $keyword = new KeywordDataDTO(
            keyword: 'test',
            source: 'test',
            searchVolume: 1000,
            competition: 0.5,
            cpc: 1.5,
        );

        $result = $this->service->validateKeyword($keyword);
        $this->assertTrue($result);
    }

    public function test_validate_keyword_rejects_invalid(): void
    {
        $keyword = new KeywordDataDTO(
            keyword: '',
            source: 'test',
            searchVolume: null,
            competition: null,
            cpc: null,
        );

        $result = $this->service->validateKeyword($keyword);
        $this->assertFalse($result);
    }
}

