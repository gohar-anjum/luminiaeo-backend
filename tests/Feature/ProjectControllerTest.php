<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_projects_requires_authentication(): void
    {
        $response = $this->getJson('/api/projects');

        $response->assertStatus(401);
    }

    public function test_get_projects_returns_user_projects(): void
    {
        $user = User::factory()->create();
        Project::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/projects');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'data']);
    }

    public function test_create_project_requires_authentication(): void
    {
        $response = $this->postJson('/api/projects', [
            'name' => 'Test Project',
        ]);

        $response->assertStatus(401);
    }

    public function test_create_project_creates_project(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects', [
                'name' => 'Test Project',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['status', 'message', 'data']);

        $this->assertDatabaseHas('projects', [
            'name' => 'Test Project',
            'user_id' => $user->id,
        ]);
    }
}

