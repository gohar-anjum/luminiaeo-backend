<?php

namespace Tests\Unit;

use App\Models\KeywordResearchJob;
use App\Services\Google\KeywordPlannerService;
// Answer The Public service is disabled - commented out as it's no longer needed
// use App\Services\Keyword\AnswerThePublicService;
use App\Services\Keyword\KeywordScraperService;
use App\Services\Keyword\SemanticClusteringService;
use App\Services\Keyword\KeywordCacheService;
use App\Services\Keyword\KeywordClusteringCacheService;
use App\Services\Keyword\CombinedKeywordService;
use App\Services\LLM\LLMClient;
use App\Services\Keyword\KeywordResearchOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class KeywordResearchOrchestratorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $mockKeywordPlanner;
    protected $mockScraper;
    protected $mockATP;
    protected $mockClustering;
    protected $mockCache;
    protected $mockClusteringCache;
    protected $mockCombined;
    protected $mockLLM;
    protected KeywordResearchOrchestratorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockKeywordPlanner = Mockery::mock(KeywordPlannerService::class);
        $this->mockScraper = Mockery::mock(KeywordScraperService::class);
        // Answer The Public service is disabled - commented out as it's no longer needed
        // $this->mockATP = Mockery::mock(AnswerThePublicService::class);
        $this->mockATP = null; // Placeholder for compatibility
        $this->mockClustering = Mockery::mock(SemanticClusteringService::class);
        $this->mockCache = Mockery::mock(KeywordCacheService::class);
        $this->mockClusteringCache = Mockery::mock(KeywordClusteringCacheService::class);
        $this->mockCombined = Mockery::mock(CombinedKeywordService::class);
        $this->mockLLM = Mockery::mock(LLMClient::class);

        // Note: Answer The Public service is disabled, so mockATP is set to null
        // The service constructor no longer accepts AnswerThePublicService parameter
        $this->service = new KeywordResearchOrchestratorService(
            $this->mockKeywordPlanner,
            null, // DataForSEOService (optional)
            $this->mockScraper,
            $this->mockClustering,
            $this->mockCache,
            $this->mockClusteringCache,
            $this->mockCombined,
            $this->mockLLM
        );
    }

    public function test_process_collects_keywords_from_multiple_sources(): void
    {
        $job = KeywordResearchJob::factory()->create([
            'query' => 'seo tools',
            'status' => 'pending',
        ]);

        $this->mockKeywordPlanner->shouldReceive('getKeywordIdeas')
            ->once()
            ->andReturn([]);

        $this->mockScraper->shouldReceive('scrapeAll')
            ->once()
            ->andReturn([]);

        // Answer The Public service is disabled - commented out as it's no longer needed
        // $this->mockATP->shouldReceive('getKeywordData')
        //     ->once()
        //     ->andReturn([]);

        $this->mockCombined->shouldReceive('getCombinedKeywords')
            ->once()
            ->andReturn([]);

        $this->mockClustering->shouldReceive('clusterKeywords')
            ->once()
            ->andReturn(['clusters' => [], 'keyword_cluster_map' => []]);

        $this->service->process($job);

        $job->refresh();
        $this->assertEquals('completed', $job->status);
    }

    public function test_process_handles_no_keywords_collected(): void
    {
        $job = KeywordResearchJob::factory()->create([
            'query' => 'nonexistent',
            'status' => 'pending',
        ]);

        $this->mockKeywordPlanner->shouldReceive('getKeywordIdeas')
            ->once()
            ->andReturn([]);

        $this->mockScraper->shouldReceive('scrapeAll')
            ->once()
            ->andReturn([]);

        // Answer The Public service is disabled - commented out as it's no longer needed
        // $this->mockATP->shouldReceive('getKeywordData')
        //     ->once()
        //     ->andReturn([]);

        $this->mockCombined->shouldReceive('getCombinedKeywords')
            ->once()
            ->andReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No keywords collected');

        $this->service->process($job);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

