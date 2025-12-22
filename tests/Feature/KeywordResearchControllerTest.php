<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\KeywordResearchJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KeywordResearchControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_research_job_requires_authentication(): void
    {
        $response = $this->postJson('/api/keyword-research', [
            'query' => 'seo tools',
        ]);

        $response->assertStatus(401);
    }

    public function test_create_research_job_validates_input(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/keyword-research', []);

        $response->assertStatus(422);
    }

    public function test_create_research_job_creates_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/keyword-research', [
                'query' => 'seo tools',
                'language_code' => 'en',
                'geoTargetId' => 2840,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['status', 'message', 'data' => ['id', 'status']]);

        $this->assertDatabaseHas('keyword_research_jobs', [
            'query' => 'seo tools',
            'user_id' => $user->id,
        ]);
    }

    public function test_get_research_jobs_list(): void
    {
        $user = User::factory()->create();
        KeywordResearchJob::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/keyword-research');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'data']);
    }

    public function test_get_research_job_status(): void
    {
        $user = User::factory()->create();
        $job = KeywordResearchJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'processing',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/keyword-research/{$job->id}/status");

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'data' => ['id', 'status']]);
    }

    public function test_get_research_job_results(): void
    {
        $user = User::factory()->create();
        $job = KeywordResearchJob::factory()->create([
            'user_id' => $user->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/keyword-research/{$job->id}/results");

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'data']);
    }
}

