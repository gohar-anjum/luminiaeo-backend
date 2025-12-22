<?php

namespace Tests\Unit;

use App\Services\DataForSEO\BacklinksService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BacklinksServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BacklinksService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.dataforseo.base_url' => 'https://api.dataforseo.com/v3',
            'services.dataforseo.login' => 'test-login',
            'services.dataforseo.password' => 'test-password',
            'services.dataforseo.summary_limit' => 100,
        ]);

        $this->service = new BacklinksService();
    }

    public function test_submit_backlinks_task_validates_domain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->submitBacklinksTask('');
    }

    public function test_submit_backlinks_task_validates_limit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->submitBacklinksTask('example.com', 1001);
    }

    public function test_submit_backlinks_task_returns_task_id(): void
    {
        Http::fake([
            'api.dataforseo.com/v3/*' => Http::response([
                'tasks' => [
                    [
                        'status_code' => 20000,
                        'id' => '1234567890',
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->submitBacklinksTask('example.com');

        $this->assertArrayHasKey('task_id', $result);
        $this->assertEquals('1234567890', $result['task_id']);
    }

    public function test_get_backlinks_results_returns_data(): void
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
                                        'target' => 'example.com',
                                        'backlinks' => 100,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->getBacklinksResults('1234567890');

        $this->assertIsArray($result);
    }
}

