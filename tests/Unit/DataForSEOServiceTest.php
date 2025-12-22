<?php

namespace Tests\Unit;

use App\DTOs\SearchVolumeDTO;
use App\DTOs\KeywordsForSiteDTO;
use App\Exceptions\DataForSEOException;
use App\Interfaces\KeywordCacheRepositoryInterface;
use App\Services\DataForSEO\DataForSEOService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class DataForSEOServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DataForSEOService $service;
    protected $mockCacheRepository;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.dataforseo.base_url' => 'https://api.dataforseo.com/v3',
            'services.dataforseo.login' => 'test-login',
            'services.dataforseo.password' => 'test-password',
            'services.dataforseo.cache_ttl' => 3600,
            'services.dataforseo.timeout' => 30,
        ]);

        $this->mockCacheRepository = Mockery::mock(KeywordCacheRepositoryInterface::class);
        $this->service = new DataForSEOService($this->mockCacheRepository);
    }

    public function test_get_search_volume_success(): void
    {
        Http::fake([
            'api.dataforseo.com/v3/*' => Http::response([
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'keyword' => 'test keyword',
                                        'search_volume' => 1000,
                                        'competition' => 0.5,
                                        'cpc' => 1.5,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->getSearchVolume(['test keyword']);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(SearchVolumeDTO::class, $result[0]);
        $this->assertEquals('test keyword', $result[0]->keyword);
        $this->assertEquals(1000, $result[0]->searchVolume);
    }

    public function test_get_search_volume_validates_empty_keywords(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Keywords array cannot be empty');
        $this->service->getSearchVolume([]);
    }

    public function test_get_search_volume_validates_max_keywords(): void
    {
        $keywords = array_fill(0, 101, 'keyword');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum 100 keywords allowed');
        $this->service->getSearchVolume($keywords);
    }

    public function test_get_search_volume_validates_keyword_length(): void
    {
        $longKeyword = str_repeat('a', 256);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Keyword exceeds maximum length');
        $this->service->getSearchVolume([$longKeyword]);
    }

    public function test_get_search_volume_caches_result(): void
    {
        Http::fake([
            'api.dataforseo.com/v3/*' => Http::response([
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'keyword' => 'cached keyword',
                                        'search_volume' => 500,
                                        'competition' => 0.3,
                                        'cpc' => 1.0,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result1 = $this->service->getSearchVolume(['cached keyword']);
        $result2 = $this->service->getSearchVolume(['cached keyword']);

        $this->assertEquals($result1[0]->keyword, $result2[0]->keyword);
        Http::assertSentCount(1);
    }

    public function test_get_search_volume_handles_api_error(): void
    {
        Http::fake([
            'api.dataforseo.com/v3/*' => Http::response([
                'tasks' => [
                    [
                        'status_code' => 40001,
                        'status_message' => 'Invalid request',
                    ],
                ],
            ], 200),
        ]);

        $this->expectException(DataForSEOException::class);
        $this->service->getSearchVolume(['test keyword']);
    }

    public function test_get_keywords_for_site_success(): void
    {
        Http::fake([
            'api.dataforseo.com/v3/*' => Http::response([
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'keyword' => 'seo tools',
                                        'search_volume' => 2000,
                                        'competition' => 0.6,
                                        'cpc' => 2.0,
                                        'target' => 'example.com',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->mockCacheRepository->shouldReceive('bulkUpdate')->once();

        $result = $this->service->getKeywordsForSite('example.com');

        $this->assertCount(1, $result);
        $this->assertInstanceOf(KeywordsForSiteDTO::class, $result[0]);
        $this->assertEquals('seo tools', $result[0]->keyword);
    }

    public function test_get_keywords_for_site_validates_empty_target(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Target website/domain cannot be empty');
        $this->service->getKeywordsForSite('');
    }

    public function test_get_keywords_for_site_normalizes_url(): void
    {
        Http::fake([
            'api.dataforseo.com/v3/*' => Http::response([
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->mockCacheRepository->shouldReceive('bulkUpdate')->once();

        $result = $this->service->getKeywordsForSite('https://example.com/');
        $this->assertIsArray($result);
    }

    public function test_get_keywords_for_site_applies_limit(): void
    {
        Http::fake([
            'api.dataforseo.com/v3/*' => Http::response([
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => array_fill(0, 10, [
                                    'keyword' => 'test',
                                    'search_volume' => 100,
                                    'competition' => 0.5,
                                    'cpc' => 1.0,
                                ]),
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->mockCacheRepository->shouldReceive('bulkUpdate')->once();

        $result = $this->service->getKeywordsForSite('example.com', 2840, 'en', true, null, null, false, null, 5);
        $this->assertCount(5, $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

