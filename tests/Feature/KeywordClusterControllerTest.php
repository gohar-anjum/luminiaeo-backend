<?php

namespace Tests\Feature;

use App\Domain\Billing\Models\Feature;
use App\Jobs\ProcessKeywordClusterJob;
use App\Models\ClusterJob;
use App\Models\KeywordClusterSnapshot;
use App\Models\User;
use App\Models\UserKeywordClusterAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KeywordClusterControllerTest extends TestCase
{
    use RefreshDatabase;

    private function seedKeywordClusterFeature(): void
    {
        Feature::query()->updateOrCreate(
            ['key' => 'keyword_clustering'],
            [
                'name' => 'Keyword cluster tree',
                'credit_cost' => 4,
                'is_active' => true,
            ]
        );
    }

    private function cacheKeyForRunningShoes(): string
    {
        return hash('sha256', json_encode([
            'kw' => 'running shoes',
            'lc' => 'en',
            'loc' => 2840,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/keyword-clusters', [
            'keyword' => 'seo tools',
        ])->assertStatus(401);
    }

    public function test_store_validates_keyword(): void
    {
        $user = User::factory()->create(['credits_balance' => 100]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/keyword-clusters', [])
            ->assertStatus(422);
    }

    public function test_store_dispatches_job_on_miss(): void
    {
        $this->seedKeywordClusterFeature();
        Queue::fake();

        $user = User::factory()->create(['credits_balance' => 100]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/keyword-clusters', [
                'keyword' => 'running shoes',
                'language_code' => 'en',
                'location_code' => 2840,
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('response.cache_hit', false)
            ->assertJsonPath('response.charged', true)
            ->assertJsonStructure(['response' => ['job_id', 'status', 'status_url', 'result_url']]);

        Queue::assertPushed(ProcessKeywordClusterJob::class);

        $this->assertDatabaseHas('cluster_jobs', [
            'user_id' => $user->id,
            'keyword' => 'running shoes',
            'status' => ClusterJob::STATUS_PENDING,
        ]);
    }

    public function test_store_no_charge_when_user_already_has_access_and_snapshot_valid(): void
    {
        $this->seedKeywordClusterFeature();

        $user = User::factory()->create(['credits_balance' => 100]);
        $cacheKey = $this->cacheKeyForRunningShoes();

        KeywordClusterSnapshot::query()->create([
            'cache_key' => $cacheKey,
            'keyword' => 'running shoes',
            'language_code' => 'en',
            'location_code' => 2840,
            'tree_json' => ['schema_version' => 1, 'tree' => ['id' => 'root', 'label' => 'running shoes', 'children' => []]],
            'expires_at' => now()->addDay(),
            'schema_version' => 1,
        ]);

        UserKeywordClusterAccess::query()->create([
            'user_id' => $user->id,
            'cache_key' => $cacheKey,
        ]);

        Queue::fake();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/keyword-clusters', [
                'keyword' => 'running shoes',
                'language_code' => 'en',
                'location_code' => 2840,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('response.cache_hit', true)
            ->assertJsonPath('response.charged', false);

        Queue::assertNotPushed(ProcessKeywordClusterJob::class);

        $user->refresh();
        $this->assertSame(100, (int) $user->credits_balance);
    }

    public function test_store_charges_first_time_when_global_snapshot_exists_but_user_has_no_access(): void
    {
        $this->seedKeywordClusterFeature();

        $user = User::factory()->create(['credits_balance' => 100]);
        $cacheKey = $this->cacheKeyForRunningShoes();

        $snapshot = KeywordClusterSnapshot::query()->create([
            'cache_key' => $cacheKey,
            'keyword' => 'running shoes',
            'language_code' => 'en',
            'location_code' => 2840,
            'tree_json' => ['schema_version' => 1, 'tree' => ['id' => 'root', 'label' => 'running shoes', 'children' => []]],
            'expires_at' => now()->addDay(),
            'schema_version' => 1,
        ]);

        Queue::fake();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/keyword-clusters', [
                'keyword' => 'running shoes',
                'language_code' => 'en',
                'location_code' => 2840,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('response.cache_hit', true)
            ->assertJsonPath('response.charged', true)
            ->assertJsonPath('response.snapshot_id', $snapshot->id);

        Queue::assertNotPushed(ProcessKeywordClusterJob::class);

        $this->assertDatabaseHas('user_keyword_cluster_access', [
            'user_id' => $user->id,
            'cache_key' => $cacheKey,
        ]);

        $user->refresh();
        $this->assertSame(96, (int) $user->credits_balance);
    }

    public function test_status_returns_404_for_other_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $job = ClusterJob::query()->create([
            'user_id' => $owner->id,
            'cache_key' => 'abc',
            'keyword' => 'x',
            'language_code' => 'en',
            'location_code' => 2840,
            'status' => ClusterJob::STATUS_PENDING,
        ]);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/keyword-clusters/{$job->id}/status")
            ->assertStatus(404);
    }
}
