<?php

namespace Tests\Unit;

use App\Jobs\FetchBacklinksResultsJob;
use App\Models\SeoTask;
use App\Services\DataForSEO\BacklinksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FetchBacklinksResultsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_fetches_backlinks_results(): void
    {
        $task = SeoTask::factory()->create([
            'task_id' => '1234567890',
            'status' => 'pending',
        ]);

        $mockService = Mockery::mock(BacklinksService::class);
        $mockService->shouldReceive('getBacklinksResults')
            ->once()
            ->with('1234567890')
            ->andReturn(['backlinks' => 100]);

        $job = new FetchBacklinksResultsJob($task->id);
        $job->handle($mockService);

        $task->refresh();
        $this->assertNotNull($task->results);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

