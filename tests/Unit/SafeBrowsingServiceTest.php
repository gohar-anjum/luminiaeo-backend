<?php

namespace Tests\Unit;

use App\Services\SafeBrowsing\SafeBrowsingService;
use Tests\TestCase;

class SafeBrowsingServiceTest extends TestCase
{
    public function test_extract_signals_clean(): void
    {
        $service = new SafeBrowsingService();

        $signals = $service->extractSignals([]);

        $this->assertSame('clean', $signals['status']);
        $this->assertSame([], $signals['threats']);
    }

    public function test_extract_signals_flagged(): void
    {
        $service = new SafeBrowsingService();

        $raw = [
            'matches' => [
                [
                    'threatType' => 'MALWARE',
                    'platformType' => 'ANY_PLATFORM',
                    'threatEntryType' => 'URL',
                    'threat' => ['url' => 'https://bad.example'],
                ],
            ],
        ];

        $signals = $service->extractSignals($raw);

        $this->assertSame('flagged', $signals['status']);
        $this->assertNotEmpty($signals['threats']);
        $this->assertSame('MALWARE', $signals['threats'][0]['threatType']);
    }
}

