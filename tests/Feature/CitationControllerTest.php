<?php

namespace Tests\Feature;

use App\Jobs\GenerateCitationQueriesJob;
use App\Models\CitationTask;
use App\Models\User;
use App\Services\LLM\LLMClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CitationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyze_endpoint_creates_task_and_dispatches_job(): void
    {
        Queue::fake();

        $mock = Mockery::mock(LLMClient::class);
        $mock->shouldReceive('generateQueries')
            ->andReturnUsing(function ($url, $count) {
                return $this->fakeQueries($count);
            })
            ->zeroOrMoreTimes();
        $this->instance(LLMClient::class, $mock);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/citations/analyze', [
            'url' => 'https://example.com',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('response.reused_existing_task', false);

        Queue::assertPushed(GenerateCitationQueriesJob::class);
    }

    public function test_analyze_reuses_completed_task_for_same_url(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        CitationTask::factory()->for($user)->completed()->create([
            'url' => 'https://example.com',
            'created_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/citations/analyze', [
            'url' => 'https://example.com/',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('response.reused_existing_task', true);

        Queue::assertNotPushed(GenerateCitationQueriesJob::class);
    }

    private function fakeQueries(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        return array_map(fn ($i) => "query {$i}", range(1, $count));
    }
}
