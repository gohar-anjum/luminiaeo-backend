<?php

namespace Tests\Unit;

use App\DTOs\FaqGenerationDTO;
use App\DTOs\FaqResponseDTO;
use App\Interfaces\FaqRepositoryInterface;
use App\Services\FAQ\FaqGeneratorService;
use App\Services\LLM\LLMClient;
use App\Services\LLM\Prompt\PlaceholderReplacer;
use App\Services\LLM\Prompt\PromptLoader;
use App\Services\LLM\Support\JsonExtractor;
use App\Services\Serp\SerpService;
use App\Services\FAQ\AlsoAskedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class FaqGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FaqGeneratorService $service;
    protected $mockLLMClient;
    protected $mockFaqRepository;
    protected $mockPromptLoader;
    protected $mockPlaceholderReplacer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.faq.cache_ttl' => 3600,
            'services.faq.timeout' => 60,
        ]);

        $this->mockLLMClient = Mockery::mock(LLMClient::class);
        $this->mockFaqRepository = Mockery::mock(FaqRepositoryInterface::class);
        $this->mockPromptLoader = Mockery::mock(PromptLoader::class);
        $this->mockPlaceholderReplacer = Mockery::mock(PlaceholderReplacer::class);

        $this->service = new FaqGeneratorService(
            $this->mockLLMClient,
            $this->mockFaqRepository,
            $this->mockPromptLoader,
            $this->mockPlaceholderReplacer
        );
    }

    public function test_generate_faqs_validates_empty_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Input field is required');
        $this->service->generateFaqs('');
    }

    public function test_generate_faqs_returns_cached_result(): void
    {
        $cachedResult = new FaqResponseDTO(
            faqs: [],
            totalCount: 0,
            source: 'cache'
        );

        $this->mockFaqRepository->shouldReceive('findByInput')
            ->once()
            ->andReturn($cachedResult);

        $result = $this->service->generateFaqs('test topic');

        $this->assertInstanceOf(FaqResponseDTO::class, $result);
        $this->assertEquals('cache', $result->source);
    }

    public function test_generate_faqs_generates_new_faqs(): void
    {
        $this->mockFaqRepository->shouldReceive('findByInput')
            ->once()
            ->andReturn(null);

        $this->mockPromptLoader->shouldReceive('load')
            ->once()
            ->andReturn([
                'system' => 'You are a helpful assistant',
                'user' => 'Generate FAQs for: {{ input }}',
            ]);

        $this->mockPlaceholderReplacer->shouldReceive('replace')
            ->once()
            ->andReturn('Generate FAQs for: test topic');

        $this->mockLLMClient->shouldReceive('generate')
            ->once()
            ->andReturn([
                'faqs' => [
                    ['question' => 'What is test?', 'answer' => 'Test is...'],
                ],
            ]);

        $this->mockFaqRepository->shouldReceive('create')
            ->once()
            ->andReturn(Mockery::mock(\App\Models\Faq::class));

        $result = $this->service->generateFaqs('test topic');

        $this->assertInstanceOf(FaqResponseDTO::class, $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

