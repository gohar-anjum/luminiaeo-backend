<?php

namespace Tests\Unit;

use App\DTOs\KeywordDataDTO;
use App\Interfaces\KeywordCacheRepositoryInterface;
use App\Models\KeywordCache;
use App\Services\Keyword\KeywordCacheService;
use App\Services\DataForSEO\DataForSEOService;
use App\Services\Serp\SerpService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class KeywordCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected KeywordCacheService $service;
    protected KeywordCacheRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(KeywordCacheRepositoryInterface::class);
        $serpService = $this->createMock(SerpService::class);
        $dataForSEOService = $this->createMock(DataForSEOService::class);

        $this->service = new KeywordCacheService(
            $this->repository,
            $serpService,
            $dataForSEOService
        );
    }

    public function test_get_keyword_data_returns_cached_data(): void
    {

        KeywordCache::create([
            'keyword' => 'cached keyword',
            'language_code' => 'en',
            'location_code' => 2840,
            'search_volume' => 1000,
            'competition' => 0.5,
            'cpc' => 1.5,
            'cached_at' => now(),
            'expires_at' => now()->addDays(30),
            'source' => 'serp_api',
        ]);

        $result = $this->service->getKeywordData(
            ['cached keyword'],
            'en',
            2840,
            'serp_api'
        );

        $this->assertCount(1, $result);
        $this->assertInstanceOf(KeywordDataDTO::class, $result[0]);
        $this->assertEquals('cached keyword', $result[0]->keyword);
    }

    public function test_get_keyword_data_skips_expired_cache(): void
    {

        KeywordCache::create([
            'keyword' => 'expired keyword',
            'language_code' => 'en',
            'location_code' => 2840,
            'cached_at' => now()->subDays(31),
            'expires_at' => now()->subDays(1),
            'source' => 'serp_api',
        ]);

        $serpService = $this->createMock(SerpService::class);
        $serpService->method('getKeywordData')
            ->willReturn([
                new \App\DTOs\SerpKeywordDataDTO(
                    keyword: 'expired keyword',
                    searchVolume: 500
                ),
            ]);

        $service = new KeywordCacheService(
            $this->repository,
            $serpService,
            $this->createMock(DataForSEOService::class)
        );

        $result = $service->getKeywordData(['expired keyword']);

        $this->assertCount(1, $result);
    }

    public function test_refresh_expired_cache(): void
    {

        KeywordCache::create([
            'keyword' => 'expired1',
            'language_code' => 'en',
            'location_code' => 2840,
            'expires_at' => now()->subDay(),
            'source' => 'serp_api',
        ]);

        KeywordCache::create([
            'keyword' => 'expired2',
            'language_code' => 'en',
            'location_code' => 2840,
            'expires_at' => now()->subDay(),
            'source' => 'serp_api',
        ]);

        $serpService = $this->createMock(SerpService::class);
        $serpService->method('getKeywordData')
            ->willReturn([
                new \App\DTOs\SerpKeywordDataDTO(keyword: 'expired1'),
                new \App\DTOs\SerpKeywordDataDTO(keyword: 'expired2'),
            ]);

        $service = new KeywordCacheService(
            $this->repository,
            $serpService,
            $this->createMock(DataForSEOService::class)
        );

        $refreshed = $service->refreshExpiredCache(100);

        $this->assertGreaterThan(0, $refreshed);
    }

    public function test_cleanup_expired_cache(): void
    {

        KeywordCache::create([
            'keyword' => 'expired',
            'language_code' => 'en',
            'location_code' => 2840,
            'expires_at' => now()->subDay(),
        ]);

        $deleted = $this->service->cleanupExpiredCache();

        $this->assertEquals(1, $deleted);
        $this->assertDatabaseMissing('keyword_cache', [
            'keyword' => 'expired',
        ]);
    }
}
