<?php

namespace Tests\Unit;

use App\Models\KeywordClusterSnapshot;
use App\Services\Admin\AdminClusterService;
use Tests\TestCase;

class AdminClusterServiceTest extends TestCase
{
    public function test_snapshot_status_valid_when_far_future(): void
    {
        $service = new AdminClusterService;
        $s = new KeywordClusterSnapshot([
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertSame('valid', $service->snapshotStatus($s));
    }

    public function test_snapshot_status_expiring_within_seven_days(): void
    {
        $service = new AdminClusterService;
        $s = new KeywordClusterSnapshot([
            'expires_at' => now()->addDays(3),
        ]);

        $this->assertSame('expiring', $service->snapshotStatus($s));
    }

    public function test_snapshot_status_expired(): void
    {
        $service = new AdminClusterService;
        $s = new KeywordClusterSnapshot([
            'expires_at' => now()->subDay(),
        ]);

        $this->assertSame('expired', $service->snapshotStatus($s));
    }
}
