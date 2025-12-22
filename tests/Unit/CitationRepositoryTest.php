<?php

namespace Tests\Unit;

use App\Models\CitationTask;
use App\Repositories\CitationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CitationRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected CitationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new CitationRepository();
    }

    public function test_create_citation_task(): void
    {
        $task = $this->repository->create([
            'url' => 'https://example.com',
            'status' => 'pending',
            'user_id' => 1,
        ]);

        $this->assertInstanceOf(CitationTask::class, $task);
        $this->assertEquals('https://example.com', $task->url);
    }

    public function test_find_returns_task(): void
    {
        $task = CitationTask::factory()->create();

        $found = $this->repository->find($task->id);

        $this->assertInstanceOf(CitationTask::class, $found);
        $this->assertEquals($task->id, $found->id);
    }

    public function test_update_modifies_task(): void
    {
        $task = CitationTask::factory()->create(['status' => 'pending']);

        $updated = $this->repository->update($task, ['status' => 'completed']);

        $this->assertEquals('completed', $updated->status);
    }

    public function test_append_results_adds_to_existing(): void
    {
        $task = CitationTask::factory()->create([
            'results' => ['queries' => ['query1']],
        ]);

        $updated = $this->repository->appendResults($task, [
            'queries' => ['query2'],
        ]);

        $this->assertCount(2, $updated->results['queries']);
    }
}

