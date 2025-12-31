<?php

namespace Tests\Unit;

use App\Services\Pbn\PbnDetectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PbnDetectorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PbnDetectorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pbn_detector.url' => 'http://pbn-detector:8081',
        ]);

        $this->service = new PbnDetectorService();
    }

    public function test_detect_pbn_returns_prediction(): void
    {
        Http::fake([
            'pbn-detector:8081/detect' => Http::response([
                'is_pbn' => false,
                'confidence' => 0.85,
                'score' => 0.15,
            ], 200),
        ]);

        $result = $this->service->detectPbn([
            'domain' => 'example.com',
            'backlinks' => [],
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('is_pbn', $result);
    }

    public function test_detect_pbn_handles_service_unavailable(): void
    {
        Http::fake([
            'pbn-detector:8081/*' => Http::response([], 500),
        ]);

        $result = $this->service->detectPbn([
            'domain' => 'example.com',
            'backlinks' => [],
        ]);

        $this->assertIsArray($result);
    }
}

