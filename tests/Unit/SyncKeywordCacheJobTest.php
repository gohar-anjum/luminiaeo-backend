<?php

namespace Tests\Unit;

use App\Jobs\SyncKeywordCacheJob;
use App\Services\Keyword\KeywordCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncKeywordCacheJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_syncs_keyword_cache(): void
    {
        $mockCacheService = Mockery::mock(KeywordCacheService::class);
        $mockCacheService->shouldReceive('refreshExpiredCache')
            ->once()
            ->andReturn(5);

        $job = new SyncKeywordCacheJob(
            keywords: ['test'],
            languageCode: 'en',
            locationCode: 2840,
            source: 'serp_api',
            refreshExpired: true,
            refreshClusters: false
        );

        $job->handle($mockCacheService);

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

