<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FAQ\FaqGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FaqControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_generate_faq_requires_authentication(): void
    {
        $response = $this->postJson('/api/faq/generate', [
            'input' => 'test topic',
        ]);

        $response->assertStatus(401);
    }

    public function test_generate_faq_validates_input(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/faq/generate', []);

        $response->assertStatus(422);
    }

    public function test_generate_faq_returns_faqs(): void
    {
        $user = User::factory()->create();

        $mockService = Mockery::mock(FaqGeneratorService::class);
        $mockService->shouldReceive('generateFaqs')
            ->once()
            ->andReturn(Mockery::mock(\App\DTOs\FaqResponseDTO::class));

        $this->app->instance(FaqGeneratorService::class, $mockService);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/faq/generate', [
                'input' => 'test topic',
            ]);

        $response->assertStatus(200);
    }

    public function test_generate_faq_respects_rate_limit(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 31; $i++) {
            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/faq/generate', [
                    'input' => 'test topic',
                ]);
        }

        $response->assertStatus(429);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

