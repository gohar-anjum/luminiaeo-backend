<?php

namespace Tests\Unit;

use App\Services\Keyword\KeywordScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordScraperServiceTest extends TestCase
{
    use RefreshDatabase;

    protected KeywordScraperService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new KeywordScraperService();
    }

    public function test_scrape_all_returns_keywords(): void
    {
        $result = $this->service->scrapeAll('seo tools', 'en');
        $this->assertIsArray($result);
    }

    public function test_scrape_all_handles_empty_result(): void
    {
        $result = $this->service->scrapeAll('nonexistent xyz123', 'en');
        $this->assertIsArray($result);
    }
}

