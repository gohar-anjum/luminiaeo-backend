<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_user_profile_requires_authentication(): void
    {
        $response = $this->getJson('/api/user/profile');

        $response->assertStatus(401);
    }

    public function test_get_user_profile_returns_user_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/user/profile');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'data' => ['id', 'name', 'email']]);
    }

    public function test_update_user_profile_requires_authentication(): void
    {
        $response = $this->putJson('/api/user/profile', [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(401);
    }

    public function test_update_user_profile_updates_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/profile', [
                'name' => 'Updated Name',
            ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertEquals('Updated Name', $user->name);
    }
}

