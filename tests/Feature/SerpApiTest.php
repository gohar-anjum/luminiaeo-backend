<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SerpApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_serp_keyword_data_endpoint_requires_authentication(): void
    {
        $response = $this->postJson('/api/serp/keywords', [
            'keywords' => ['test keyword'],
        ]);

        $response->assertStatus(401);
    }

    public function test_serp_keyword_data_endpoint_validates_input(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/serp/keywords', [
                'keywords' => [],
            ]);

        $response->assertStatus(422);
    }

    public function test_serp_keyword_data_endpoint_success(): void
    {
        config([
            'services.serp.base_url' => 'https:
            'services.serp.api_key' => 'test-key',
        ]);

        Http::fake([
            'api.serpapi.com/keywords' => Http::response([
                'data' => [
                    [
                        'keyword' => 'test keyword',
                        'search_volume' => 1000,
                        'competition' => 0.5,
                        'cpc' => 1.5,
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/serp/keywords', [
                'keywords' => ['test keyword'],
                'language_code' => 'en',
                'location_code' => 2840,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'keyword',
                        'search_volume',
                        'competition',
                        'cpc',
                    ],
                ],
            ]);
    }
}
