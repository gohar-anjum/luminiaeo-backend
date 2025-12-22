<?php

namespace Tests\Unit;

use App\Jobs\ProcessKeywordIntentJob;
use App\Models\Keyword;
use App\Services\LLM\LLMClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessKeywordIntentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_processes_keyword_intent(): void
    {
        $keyword = Keyword::factory()->create(['keyword' => 'seo tools']);

        $mockLLMClient = Mockery::mock(LLMClient::class);
        $mockLLMClient->shouldReceive('analyzeKeywordIntent')
            ->once()
            ->with('seo tools')
            ->andReturn([
                'intent' => 'informational',
                'category' => 'research',
            ]);

        $this->app->instance(LLMClient::class, $mockLLMClient);

        $job = new ProcessKeywordIntentJob($keyword->id);
        $job->handle($mockLLMClient);

        $keyword->refresh();
        $this->assertEquals('informational', $keyword->intent);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

