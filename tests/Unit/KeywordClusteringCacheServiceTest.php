<?php

namespace Tests\Unit;

use App\DTOs\KeywordDataDTO;
use App\Services\Keyword\KeywordClusteringCacheService;
use App\Services\Keyword\SemanticClusteringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class KeywordClusteringCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $mockClusteringService;
    protected KeywordClusteringCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClusteringService = Mockery::mock(SemanticClusteringService::class);
        $this->service = new KeywordClusteringCacheService($this->mockClusteringService);
    }

    public function test_cluster_and_cache_returns_clusters(): void
    {
        $keywords = [
            new KeywordDataDTO(keyword: 'seo tools', source: 'test', searchVolume: 1000, competition: 0.5, cpc: 1.5),
        ];

        $this->mockClusteringService->shouldReceive('clusterKeywords')
            ->once()
            ->andReturn([
                'clusters' => [],
                'keyword_cluster_map' => [],
            ]);

        $result = $this->service->clusterAndCache($keywords, 'en', 2840, 5);

        $this->assertArrayHasKey('clusters', $result);
        $this->assertArrayHasKey('keyword_cluster_map', $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

