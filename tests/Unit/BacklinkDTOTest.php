<?php

namespace Tests\Unit;

use App\DTOs\BacklinkDTO;
use Tests\TestCase;

class BacklinkDTOTest extends TestCase
{
    public function test_can_apply_whois_and_detection_data(): void
    {
        $dto = BacklinkDTO::fromArray([
            'url_from' => 'https:
            'domain_from' => 'example.com',
            'anchor' => 'buy widgets',
            'domain_rank' => 120,
            'dofollow' => true,
            'first_seen' => '2025-01-01 00:00:00',
        ], 'https:

        $dto->applyWhoisSignals([
            'registrar' => 'Test Registrar',
            'domain_age_days' => 365,
        ]);

        $dto->applyDetection([
            'pbn_probability' => 0.82,
            'risk_level' => 'high',
            'reasons' => ['shared_ip'],
            'signals' => [
                'asn' => 'AS13335',
                'hosting_provider' => 'Cloudflare',
            ],
        ]);

        $dto->applySafeBrowsing([
            'status' => 'flagged',
            'threats' => [['threatType' => 'MALWARE']],
            'checked_at' => now()->toIso8601String(),
        ]);

        $array = $dto->toDatabaseArray();

        $this->assertSame('Test Registrar', $array['whois_registrar']);
        $this->assertSame(365, $array['domain_age_days']);
        $this->assertSame(0.82, $array['pbn_probability']);
        $this->assertSame('high', $array['risk_level']);
        $this->assertSame('AS13335', $array['asn']);
        $this->assertSame('flagged', $array['safe_browsing_status']);
    }
}
