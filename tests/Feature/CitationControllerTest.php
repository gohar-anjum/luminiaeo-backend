<?php

namespace Tests\Feature;

use App\Jobs\ProcessCitationTaskJob;
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
            'url' => 'https:
        ]);

        $response->assertStatus(202)->assertJsonStructure(['task_id', 'status', 'message']);

        Queue::assertPushed(ProcessCitationTaskJob::class);
    }

    private function fakeQueries(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        return array_map(fn ($i) => "query {$i}", range(1, $count));
    }
}
