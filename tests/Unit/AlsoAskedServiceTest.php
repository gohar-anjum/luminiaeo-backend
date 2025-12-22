<?php

namespace Tests\Unit;

use App\Interfaces\KeywordCacheRepositoryInterface;
use App\Services\FAQ\AlsoAskedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AlsoAskedServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AlsoAskedService $service;
    protected $mockCacheRepository;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.alsoasked.base_url' => 'https://alsoaskedapi.com/v1',
            'services.alsoasked.api_key' => 'test-api-key',
            'services.alsoasked.cache_ttl' => 3600,
            'services.alsoasked.timeout' => 30,
        ]);

        $this->mockCacheRepository = Mockery::mock(KeywordCacheRepositoryInterface::class);
        $this->service = new AlsoAskedService($this->mockCacheRepository);
    }

    public function test_is_available_returns_true_when_configured(): void
    {
        $this->assertTrue($this->service->isAvailable());
    }

    public function test_is_available_returns_false_when_not_configured(): void
    {
        config(['services.alsoasked.api_key' => null]);
        $service = new AlsoAskedService($this->mockCacheRepository);
        $this->assertFalse($service->isAvailable());
    }

    public function test_search_returns_questions(): void
    {
        Http::fake([
            'alsoaskedapi.com/v1/search' => Http::response([
                'questions' => [
                    'What is SEO?',
                    'How does SEO work?',
                ],
            ], 200),
        ]);

        $this->mockCacheRepository->shouldReceive('bulkUpdate')->once();

        $result = $this->service->search('seo');

        $this->assertCount(2, $result);
        $this->assertContains('What is SEO?', $result);
    }

    public function test_search_handles_nested_structure(): void
    {
        Http::fake([
            'alsoaskedapi.com/v1/search' => Http::response([
                'results' => [
                    [
                        'questions' => [
                            ['question' => 'Question 1'],
                            ['text' => 'Question 2'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->mockCacheRepository->shouldReceive('bulkUpdate')->once();

        $result = $this->service->search('test');
        $this->assertCount(2, $result);
    }

    public function test_search_caches_results(): void
    {
        Http::fake([
            'alsoaskedapi.com/v1/search' => Http::response([
                'questions' => ['Cached question'],
            ], 200),
        ]);

        $this->mockCacheRepository->shouldReceive('bulkUpdate')->once();

        $result1 = $this->service->search('test');
        $result2 = $this->service->search('test');

        $this->assertEquals($result1, $result2);
        Http::assertSentCount(1);
    }

    public function test_extract_keywords_from_questions(): void
    {
        $questions = [
            'What is SEO?',
            'How does SEO work?',
            'Why is SEO important?',
        ];

        $keywords = $this->service->extractKeywordsFromQuestions($questions);

        $this->assertNotEmpty($keywords);
        $this->assertContains('seo', $keywords);
    }

    public function test_get_keywords_combines_questions_and_extracted_keywords(): void
    {
        Http::fake([
            'alsoaskedapi.com/v1/search' => Http::response([
                'questions' => ['What is SEO?'],
            ], 200),
        ]);

        $this->mockCacheRepository->shouldReceive('bulkUpdate')->once();

        $result = $this->service->getKeywords('seo');

        $this->assertNotEmpty($result);
    }

    public function test_search_returns_empty_when_service_unavailable(): void
    {
        config(['services.alsoasked.api_key' => null]);
        $service = new AlsoAskedService($this->mockCacheRepository);
        $result = $service->search('test');
        $this->assertEmpty($result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

