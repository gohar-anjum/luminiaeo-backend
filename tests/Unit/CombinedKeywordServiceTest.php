<?php

namespace Tests\Unit;

use App\DTOs\KeywordDataDTO;
use App\DTOs\KeywordsForSiteDTO;
use App\Interfaces\KeywordCacheRepositoryInterface;
use App\Services\DataForSEO\DataForSEOService;
use App\Services\FAQ\AlsoAskedService;
use App\Services\Keyword\CombinedKeywordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CombinedKeywordServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CombinedKeywordService $service;
    protected $mockDataForSEOService;
    protected $mockAlsoAskedService;
    protected $mockCacheRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDataForSEOService = Mockery::mock(DataForSEOService::class);
        $this->mockAlsoAskedService = Mockery::mock(AlsoAskedService::class);
        $this->mockCacheRepository = Mockery::mock(KeywordCacheRepositoryInterface::class);

        $this->service = new CombinedKeywordService(
            $this->mockDataForSEOService,
            $this->mockAlsoAskedService,
            $this->mockCacheRepository
        );
    }

    public function test_get_combined_keywords_combines_sources(): void
    {
        $dto = Mockery::mock(KeywordsForSiteDTO::class);
        $dto->keyword = 'seo tools';
        $dto->shouldReceive('toKeywordDataDTO')->andReturn(new KeywordDataDTO(
            keyword: 'seo tools',
            source: 'dataforseo_keywords_for_site',
            searchVolume: 1000,
            competition: 0.5,
            cpc: 1.5,
        ));

        $this->mockDataForSEOService->shouldReceive('getKeywordsForSite')
            ->once()
            ->andReturn([$dto]);

        $this->mockAlsoAskedService->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $this->mockAlsoAskedService->shouldReceive('getKeywords')
            ->once()
            ->andReturn(['seo software']);

        $result = $this->service->getCombinedKeywords('example.com');

        $this->assertCount(2, $result);
        $this->assertEquals('seo tools', $result[0]->keyword);
        $this->assertEquals('seo software', $result[1]->keyword);
    }

    public function test_get_combined_keywords_deduplicates(): void
    {
        $dto = Mockery::mock(KeywordsForSiteDTO::class);
        $dto->keyword = 'seo tools';
        $dto->shouldReceive('toKeywordDataDTO')->andReturn(new KeywordDataDTO(
            keyword: 'seo tools',
            source: 'dataforseo_keywords_for_site',
            searchVolume: 1000,
            competition: 0.5,
            cpc: 1.5,
        ));

        $this->mockDataForSEOService->shouldReceive('getKeywordsForSite')
            ->once()
            ->andReturn([$dto]);

        $this->mockAlsoAskedService->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $this->mockAlsoAskedService->shouldReceive('getKeywords')
            ->once()
            ->andReturn(['seo tools']);

        $result = $this->service->getCombinedKeywords('example.com');

        $this->assertCount(1, $result);
    }

    public function test_get_combined_keywords_applies_limit(): void
    {
        $dtos = [];
        for ($i = 0; $i < 10; $i++) {
            $dto = Mockery::mock(KeywordsForSiteDTO::class);
            $dto->keyword = "keyword {$i}";
            $dto->shouldReceive('toKeywordDataDTO')->andReturn(new KeywordDataDTO(
                keyword: "keyword {$i}",
                source: 'dataforseo_keywords_for_site',
                searchVolume: 100,
                competition: 0.5,
                cpc: 1.0,
            ));
            $dtos[] = $dto;
        }

        $this->mockDataForSEOService->shouldReceive('getKeywordsForSite')
            ->once()
            ->andReturn($dtos);

        $this->mockAlsoAskedService->shouldReceive('isAvailable')
            ->once()
            ->andReturn(false);

        $result = $this->service->getCombinedKeywords('example.com', 2840, 'en', 5);

        $this->assertCount(5, $result);
    }

    public function test_get_combined_keywords_handles_dataforseo_failure(): void
    {
        $this->mockDataForSEOService->shouldReceive('getKeywordsForSite')
            ->once()
            ->andThrow(new \Exception('API Error'));

        $this->mockAlsoAskedService->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $this->mockAlsoAskedService->shouldReceive('getKeywords')
            ->once()
            ->andReturn(['seo tools']);

        $result = $this->service->getCombinedKeywords('example.com');

        $this->assertCount(1, $result);
        $this->assertEquals('seo tools', $result[0]->keyword);
    }

    public function test_get_combined_keywords_handles_alsoasked_failure(): void
    {
        $dto = Mockery::mock(KeywordsForSiteDTO::class);
        $dto->keyword = 'seo tools';
        $dto->shouldReceive('toKeywordDataDTO')->andReturn(new KeywordDataDTO(
            keyword: 'seo tools',
            source: 'dataforseo_keywords_for_site',
            searchVolume: 1000,
            competition: 0.5,
            cpc: 1.5,
        ));

        $this->mockDataForSEOService->shouldReceive('getKeywordsForSite')
            ->once()
            ->andReturn([$dto]);

        $this->mockAlsoAskedService->shouldReceive('isAvailable')
            ->once()
            ->andReturn(true);

        $this->mockAlsoAskedService->shouldReceive('getKeywords')
            ->once()
            ->andThrow(new \Exception('API Error'));

        $result = $this->service->getCombinedKeywords('example.com');

        $this->assertCount(1, $result);
        $this->assertEquals('seo tools', $result[0]->keyword);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

