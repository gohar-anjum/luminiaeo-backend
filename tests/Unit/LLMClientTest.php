<?php

namespace Tests\Unit;

use App\Services\LLM\LLMClient;
use App\Services\LLM\Prompt\PlaceholderReplacer;
use App\Services\LLM\Prompt\PromptLoader;
use App\Services\LLM\Support\JsonExtractor;
use App\Services\LLM\Transformers\KeywordIntentParser;
use App\Services\LLM\Failures\ProviderCircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class LLMClientTest extends TestCase
{
    use RefreshDatabase;

    protected LLMClient $client;
    protected $mockPromptLoader;
    protected $mockPlaceholderReplacer;
    protected $mockCircuitBreaker;
    protected $mockKeywordParser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockPromptLoader = Mockery::mock(PromptLoader::class);
        $this->mockPlaceholderReplacer = Mockery::mock(PlaceholderReplacer::class);
        $this->mockCircuitBreaker = Mockery::mock(ProviderCircuitBreaker::class);
        $this->mockKeywordParser = Mockery::mock(KeywordIntentParser::class);

        $this->client = new LLMClient(
            $this->mockPromptLoader,
            $this->mockPlaceholderReplacer,
            $this->mockCircuitBreaker,
            $this->mockKeywordParser
        );
    }

    public function test_analyze_keyword_intent_returns_analysis(): void
    {
        $this->mockPromptLoader->shouldReceive('load')
            ->once()
            ->andReturn([
                'system' => 'You analyze keyword intent',
                'user' => 'Analyze: {{ keyword }}',
            ]);

        $this->mockPlaceholderReplacer->shouldReceive('replace')
            ->once()
            ->andReturn('Analyze: seo tools');

        $this->mockCircuitBreaker->shouldReceive('canUseProvider')
            ->once()
            ->andReturn(true);

        $this->mockCircuitBreaker->shouldReceive('clearFailures')
            ->once();

        $this->mockKeywordParser->shouldReceive('parse')
            ->once()
            ->andReturn([
                'intent' => 'informational',
                'category' => 'research',
            ]);

        $result = $this->client->analyzeKeywordIntent('seo tools');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('intent', $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

