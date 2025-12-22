<?php

namespace Tests\Unit;

use App\Jobs\ProcessCitationTaskJob;
use App\Models\CitationTask;
use App\Services\CitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessCitationTaskJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_processes_citation_task(): void
    {
        $task = CitationTask::factory()->create([
            'status' => 'pending',
        ]);

        $mockService = Mockery::mock(CitationService::class);
        $mockService->shouldReceive('processTask')
            ->once()
            ->with(Mockery::on(function ($arg) use ($task) {
                return $arg->id === $task->id;
            }));

        $job = new ProcessCitationTaskJob($task->id);
        $job->handle($mockService);

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

