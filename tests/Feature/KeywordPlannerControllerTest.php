<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DataForSEO\DataForSEOService;
use App\Services\Keyword\CombinedKeywordService;
use App\Services\Keyword\SemanticClusteringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class KeywordPlannerControllerTest extends TestCase
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

    public function test_get_keyword_ideas_requires_authentication(): void
    {
        $response = $this->getJson('/api/keyword-planner/ideas?keyword=test');

        $response->assertStatus(401);
    }

    public function test_get_keyword_ideas_returns_keywords(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/keyword-planner/ideas?keyword=seo');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'count', 'data']);
    }

    public function test_get_keywords_for_site_requires_authentication(): void
    {
        $response = $this->postJson('/api/keyword-planner/for-site', [
            'target' => 'example.com',
        ]);

        $response->assertStatus(401);
    }

    public function test_get_keywords_for_site_validates_input(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/keyword-planner/for-site', []);

        $response->assertStatus(422);
    }

    public function test_get_keywords_for_site_returns_keywords(): void
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
            ->postJson('/api/keyword-planner/for-site', [
                'target' => 'example.com',
                'location_code' => 2840,
                'language_code' => 'en',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'message', 'data']);
    }

    public function test_get_combined_keywords_with_clusters(): void
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
            'clustering-service:8000/*' => Http::response([
                'cluster_map' => ['seo tools' => 0],
                'cluster_labels' => ['Seo Tools'],
                'num_clusters' => 1,
                'cluster_sizes' => [0 => 1],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/keyword-planner/combined-with-clusters', [
                'target' => 'example.com',
                'enable_clustering' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'message', 'data' => ['keywords', 'clusters']]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

