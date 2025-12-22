<?php

namespace Tests\Unit;

use App\Interfaces\CitationRepositoryInterface;
use App\Jobs\CitationChunkJob;
use App\Models\CitationTask;
use App\Services\CitationService;
use App\Services\LLM\LLMClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CitationChunkJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_chunk_job_persists_results_and_finalizes_task(): void
    {
        $task = CitationTask::create([
            'url' => 'https:
            'status' => CitationTask::STATUS_PROCESSING,
            'queries' => [
                0 => 'example query one',
                1 => 'example query two',
            ],
        ]);

        $gptPayload = [
            'citation_found' => true,
            'confidence' => 85,
            'citation_references' => ['https:
            'explanation' => 'found evidence',
            'raw_response' => null,
            'provider' => 'gpt',
        ];

        $geminiPayload = [
            'citation_found' => false,
            'confidence' => 0,
            'citation_references' => [],
            'explanation' => 'not available',
            'raw_response' => null,
            'provider' => 'gemini',
        ];

        $batchResponseGpt = [
            0 => array_merge($gptPayload, ['query' => 'example query one', 'competitors' => [['domain' => 'competitor.test', 'url' => 'https:
            1 => array_merge($gptPayload, ['query' => 'example query two', 'competitors' => []]),
        ];

        $batchResponseGemini = [
            0 => array_merge($geminiPayload, ['query' => 'example query one', 'competitors' => []]),
            1 => array_merge($geminiPayload, ['query' => 'example query two', 'competitors' => []]),
        ];

        $llm = Mockery::mock(LLMClient::class);
        $llm->shouldReceive('batchValidateCitations')
            ->once()
            ->with($task->queries, $task->url, 'openai')
            ->andReturn($batchResponseGpt);
        $llm->shouldReceive('batchValidateCitations')
            ->once()
            ->with($task->queries, $task->url, 'gemini')
            ->andReturn($batchResponseGemini);

        $this->instance(LLMClient::class, $llm);
        $repository = app(CitationRepositoryInterface::class);
        $service = app(CitationService::class);

        $job = new CitationChunkJob($task->id, $task->queries, 0, count($task->queries));
        $job->handle($repository, $service, $llm);

        $task->refresh();

        $this->assertEquals(CitationTask::STATUS_COMPLETED, $task->status);
        $this->assertArrayHasKey('0', $task->results['by_query']);
        $this->assertNotEmpty($task->competitors);
        $this->assertEquals(50.0, $task->meta['gpt_score']);
    }
}
