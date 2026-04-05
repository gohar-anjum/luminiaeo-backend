<?php

namespace Tests\Feature;

use App\Domain\Billing\Models\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') === 'sqlite' || ($_ENV['DB_CONNECTION'] ?? '') === 'sqlite') {
            $this->markTestSkipped('Admin panel feature tests require MySQL (SQLite migrations use information_schema).');
        }
        parent::setUp();
    }

    public function test_non_admin_cannot_access_dashboard_stats(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/dashboard/stats')->assertForbidden();
    }

    public function test_admin_receives_dashboard_stats_shape(): void
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonStructure([
                'total_users',
                'new_users_today',
                'total_backlinks',
                'new_backlinks_today',
                'api_calls_today',
                'api_cache_hit_rate',
                'total_credits_sold',
                'credits_used_today',
                'active_subscriptions',
                'product_activity' => [
                    'totals',
                    'today',
                ],
                'upstream_api_cache' => [
                    'calls_today',
                    'cache_hit_rate',
                    'description',
                ],
            ]);
    }

    public function test_suspended_non_admin_cannot_hit_protected_api(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['suspended_at' => now()])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/user')->assertForbidden();
    }

    public function test_admin_can_suspend_user(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/users/{$target->id}/suspend");
        $response->assertOk();
        $this->assertNotNull($response->json('suspended_at'));

        $this->assertNotNull($target->fresh()->suspended_at);
    }

    public function test_admin_users_index_lists_customers_only(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create(['email' => 'customer@example.com']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/users?per_page=50');
        $response->assertOk();
        $emails = collect($response->json('data'))->pluck('email')->all();
        $this->assertContains($customer->email, $emails);
        $this->assertNotContains($admin->email, $emails);
    }

    public function test_non_admin_cannot_list_features(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/features')->assertForbidden();
    }

    public function test_admin_can_list_and_create_and_patch_feature(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        Feature::query()->create([
            'key' => 'test_feature_one',
            'name' => 'Test One',
            'credit_cost' => 3,
            'is_active' => true,
        ]);

        $list = $this->getJson('/api/admin/features')->assertOk();
        $list->assertJsonStructure(['data' => [['id', 'key', 'name', 'credit_cost', 'is_active', 'created_at', 'updated_at']]]);
        $this->assertTrue(collect($list->json('data'))->contains(fn (array $row) => $row['key'] === 'test_feature_one'));

        $create = $this->postJson('/api/admin/features', [
            'key' => 'new_billing_feature',
            'name' => 'New Feature',
            'credit_cost' => 7,
        ])->assertCreated();
        $create->assertJsonPath('key', 'new_billing_feature');
        $create->assertJsonPath('credit_cost', 7);
        $create->assertJsonPath('is_active', true);

        $id = (int) $create->json('id');
        $patch = $this->patchJson("/api/admin/features/{$id}", [
            'credit_cost' => 12,
            'is_active' => false,
        ])->assertOk();
        $patch->assertJsonPath('credit_cost', 12);
        $patch->assertJsonPath('is_active', false);

        $this->postJson('/api/admin/features', [
            'key' => 'Invalid-Key',
            'name' => 'Bad',
            'credit_cost' => 1,
        ])->assertUnprocessable();

        $this->patchJson("/api/admin/features/{$id}", [])->assertUnprocessable();
    }
}
