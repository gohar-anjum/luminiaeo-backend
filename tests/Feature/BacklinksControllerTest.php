<?php

namespace Tests\Feature;

use App\Interfaces\DataForSEO\BacklinksRepositoryInterface;
use App\Models\SeoTask;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class BacklinksControllerTest extends TestCase
{

    public function test_submit_endpoint_returns_pbn_payload(): void
    {
        $user = User::factory()->make();
        Sanctum::actingAs($user);

        $seoTask = new SeoTask([
            'task_id' => 'task-123',
            'domain' => 'https://example.com',
            'status' => SeoTask::STATUS_COMPLETED,
            'submitted_at' => now(),
            'completed_at' => now(),
            'result' => [
                'backlinks' => [
                    'items' => [
                        ['source_url' => 'https://source.example.com'],
                    ],
                    'items_count' => 1,
                ],
                'summary' => ['total_backlinks' => 1],
                'pbn_detection' => ['summary' => ['high_risk_count' => 0]],
            ],
        ]);

        $mock = Mockery::mock(BacklinksRepositoryInterface::class);
        $mock->shouldReceive('createTask')->once()->andReturn($seoTask);

        $this->app->instance(BacklinksRepositoryInterface::class, $mock);

        $response = $this->postJson('/api/seo/backlinks/submit', [
            'domain' => 'https://example.com',
            'limit' => 10,
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'task_id' => 'task-123',
                'domain' => 'https://example.com',
            ])
            ->assertJsonStructure([
                'response' => [
                    'backlinks',
                    'summary',
                    'pbn_detection',
                ],
            ]);
    }

    public function test_harmful_endpoint_returns_backlinks(): void
    {
        $user = User::factory()->make();
        Sanctum::actingAs($user);

        $mock = Mockery::mock(BacklinksRepositoryInterface::class);
        $mock->shouldReceive('getHarmfulBacklinks')
            ->once()
            ->andReturn([
                ['source_url' => 'https://spam.example.com', 'risk_level' => 'high'],
            ]);

        $this->app->instance(BacklinksRepositoryInterface::class, $mock);

        $response = $this->postJson('/api/seo/backlinks/harmful', [
            'domain' => 'https://example.com',
        ]);

        $response->assertOk()
            ->assertJsonFragment([
                'count' => 1,
            ]);
    }
}

