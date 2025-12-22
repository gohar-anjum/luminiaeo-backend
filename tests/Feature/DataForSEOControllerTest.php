<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DataForSEOControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.dataforseo.base_url' => 'https://api.dataforseo.com/v3',
            'services.dataforseo.login' => 'test-login',
            'services.dataforseo.password' => 'test-password',
        ]);
    }

    public function test_search_volume_requires_authentication(): void
    {
        $response = $this->postJson('/api/seo/keywords/search-volume', [
            'keywords' => ['test'],
        ]);

        $response->assertStatus(401);
    }

    public function test_search_volume_validates_input(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/seo/keywords/search-volume', []);

        $response->assertStatus(422);
    }

    public function test_search_volume_returns_data(): void
    {
        Http::fake([
            'api.dataforseo.com/v3/*' => Http::response([
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'keyword' => 'seo tools',
                                        'search_volume' => 1000,
                                        'competition' => 0.5,
                                        'cpc' => 1.5,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/seo/keywords/search-volume', [
                'keywords' => ['seo tools'],
                'language_code' => 'en',
                'location_code' => 2840,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'message', 'data']);
    }

    public function test_keywords_for_site_requires_authentication(): void
    {
        $response = $this->postJson('/api/seo/keywords/for-site', [
            'target' => 'example.com',
        ]);

        $response->assertStatus(401);
    }

    public function test_keywords_for_site_returns_keywords(): void
    {
        Http::fake([
            'api.dataforseo.com/v3/*' => Http::response([
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'result' => [
                            [
                                'items' => [
                                    [
                                        'keyword' => 'seo tools',
                                        'search_volume' => 1000,
                                        'competition' => 0.5,
                                        'cpc' => 1.5,
                                        'target' => 'example.com',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/seo/keywords/for-site', [
                'target' => 'example.com',
                'location_code' => 2840,
                'language_code' => 'en',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'message', 'data']);
    }

    public function test_search_volume_respects_rate_limit(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 61; $i++) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/seo/keywords/search-volume', [
                    'keywords' => ['test'],
                ]);
        }

        $response->assertStatus(429);
    }
}

