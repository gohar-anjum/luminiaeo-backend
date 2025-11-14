<?php

namespace Tests\Unit;

use App\Services\Whois\WhoisLookupService;
use Tests\TestCase;

class WhoisLookupServiceTest extends TestCase
{
    public function test_extract_signals_for_registered_domain(): void
    {
        $service = new WhoisLookupService();

        $payload = [
            'WhoisRecord' => [
                'domainName' => 'kelpiescabs.co.uk',
                'registrarName' => 'Realtime Register BV t/a Axidomains [Tag = AXIDOMAINS]',
                'estimatedDomainAge' => 291,
            ],
        ];

        $signals = $service->extractSignals($payload);

        $this->assertSame('kelpiescabs.co.uk', $signals['domain']);
        $this->assertSame('Realtime Register BV t/a Axidomains [Tag = AXIDOMAINS]', $signals['registrar']);
        $this->assertSame(291, $signals['domain_age_days']);
        $this->assertTrue($signals['registered']);
    }

    public function test_extract_signals_for_missing_domain(): void
    {
        $service = new WhoisLookupService();

        $payload = [
            'WhoisRecord' => [
                'domainName' => 'kelpiescab.co.uk',
                'dataError' => 'MISSING_WHOIS_DATA',
            ],
        ];

        $signals = $service->extractSignals($payload);

        $this->assertSame('kelpiescab.co.uk', $signals['domain']);
        $this->assertFalse($signals['registered']);
        $this->assertNull($signals['registrar']);
    }
}

