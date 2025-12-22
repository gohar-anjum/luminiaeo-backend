<?php

namespace Tests\Unit;

use App\Jobs\ProcessKeywordResearchJob;
use App\Models\KeywordResearchJob;
use App\Services\Keyword\KeywordResearchOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProcessKeywordResearchJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_processes_keyword_research(): void
    {
        $job = KeywordResearchJob::factory()->create([
            'status' => 'pending',
            'query' => 'seo tools',
        ]);

        $mockOrchestrator = Mockery::mock(KeywordResearchOrchestratorService::class);
        $mockOrchestrator->shouldReceive('process')
            ->once()
            ->with(Mockery::on(function ($arg) use ($job) {
                return $arg->id === $job->id;
            }));

        $this->app->instance(KeywordResearchOrchestratorService::class, $mockOrchestrator);

        $processJob = new ProcessKeywordResearchJob($job);
        $processJob->handle($mockOrchestrator);

        $this->assertTrue(true);
    }

    public function test_job_handles_exceptions(): void
    {
        $job = KeywordResearchJob::factory()->create([
            'status' => 'pending',
        ]);

        $mockOrchestrator = Mockery::mock(KeywordResearchOrchestratorService::class);
        $mockOrchestrator->shouldReceive('process')
            ->once()
            ->andThrow(new \Exception('Processing failed'));

        $processJob = new ProcessKeywordResearchJob($job);

        try {
            $processJob->handle($mockOrchestrator);
        } catch (\Exception $e) {
            $this->assertEquals('Processing failed', $e->getMessage());
        }

        $job->refresh();
        $this->assertEquals('failed', $job->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

