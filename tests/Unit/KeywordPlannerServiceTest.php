<?php

namespace Tests\Unit;

use App\Services\Google\KeywordPlannerService;
use Google\Ads\GoogleAds\Lib\V16\GoogleAdsClient;
use Google\Ads\GoogleAds\V16\Services\GenerateKeywordIdeasResponse;
use Google\Ads\GoogleAds\V16\Services\GenerateKeywordIdeaResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class KeywordPlannerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected KeywordPlannerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_ads.developer_token' => 'test-token',
            'services.google_ads.client_id' => 'test-client-id',
            'services.google_ads.client_secret' => 'test-secret',
            'services.google_ads.refresh_token' => 'test-refresh-token',
        ]);

        $this->service = new KeywordPlannerService();
    }

    public function test_get_keyword_ideas_returns_array(): void
    {
        $result = $this->service->getKeywordIdeas('seo tools');
        $this->assertIsArray($result);
    }

    public function test_get_keyword_ideas_handles_empty_result(): void
    {
        $result = $this->service->getKeywordIdeas('nonexistent keyword xyz123');
        $this->assertIsArray($result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

