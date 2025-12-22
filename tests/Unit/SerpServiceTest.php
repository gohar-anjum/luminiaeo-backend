<?php

namespace Tests\Unit;

use App\DTOs\SerpKeywordDataDTO;
use App\Exceptions\SerpException;
use App\Services\Serp\SerpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SerpServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SerpService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.serp.base_url' => 'https:
            'services.serp.api_key' => 'test-api-key',
            'services.serp.cache_ttl' => 3600,
            'services.serp.timeout' => 60,
        ]);

        $this->service = new SerpService();
    }

    public function test_get_keyword_data_success(): void
    {
        Http::fake([
            'api.serpapi.com/keywords' => Http::response([
                'data' => [
                    [
                        'keyword' => 'test keyword',
                        'search_volume' => 1000,
                        'competition' => 0.5,
                        'cpc' => 1.5,
                        'difficulty' => 60,
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->getKeywordData(['test keyword']);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(SerpKeywordDataDTO::class, $result[0]);
        $this->assertEquals('test keyword', $result[0]->keyword);
        $this->assertEquals(1000, $result[0]->searchVolume);
    }

    public function test_get_keyword_data_caches_result(): void
    {
        Http::fake([
            'api.serpapi.com/keywords' => Http::response([
                'data' => [
                    [
                        'keyword' => 'cached keyword',
                        'search_volume' => 500,
                    ],
                ],
            ], 200),
        ]);

        $result1 = $this->service->getKeywordData(['cached keyword']);

        $result2 = $this->service->getKeywordData(['cached keyword']);

        $this->assertEquals($result1[0]->keyword, $result2[0]->keyword);

        Http::assertSentCount(1);
    }

    public function test_get_keyword_data_validates_keywords(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->getKeywordData([]);
    }

    public function test_get_keyword_data_handles_api_error(): void
    {
        Http::fake([
            'api.serpapi.com/keywords' => Http::response([
                'error' => [
                    'message' => 'API Error',
                    'code' => 500,
                ],
            ], 500),
        ]);

        $this->expectException(SerpException::class);
        $this->service->getKeywordData(['test keyword']);
    }
}
