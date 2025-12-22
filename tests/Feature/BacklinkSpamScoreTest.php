<?php

namespace Tests\Feature;

use App\DTOs\BacklinkDTO;
use Tests\TestCase;

class BacklinkSpamScoreTest extends TestCase
{
    public function test_spam_score_extraction_from_dataforseo_response(): void
    {

        $dataForSeoItem = [
            'type' => 'backlink',
            'domain_from' => 'australianwebdirectory.pro',
            'url_from' => 'https:
            'url_to' => 'https:
            'domain_to' => 'ecomtechagency.com',
            'backlink_spam_score' => 70,
            'domain_from_rank' => 77,
            'domain_from_ip' => '118.139.178.200',
            'anchor' => 'ecomtechagency.com',
            'dofollow' => true,
            'first_seen' => '2025-08-07 05:01:58 +00:00',
            'last_seen' => '2025-09-16 01:08:10 +00:00',
            'links_count' => 1,
        ];

        $dto = BacklinkDTO::fromArray($dataForSeoItem, 'https:

        $this->assertNotNull($dto->backlinkSpamScore, 'Spam score should be extracted');
        $this->assertEquals(70, $dto->backlinkSpamScore, 'Spam score should match DataForSEO value');
    }

    public function test_spam_score_in_array_output(): void
    {
        $dataForSeoItem = [
            'url_from' => 'https:
            'domain_from' => 'example.com',
            'backlink_spam_score' => 85,
            'dofollow' => true,
        ];

        $dto = BacklinkDTO::fromArray($dataForSeoItem, 'https:
        $array = $dto->toArray();

        $this->assertArrayHasKey('backlink_spam_score', $array);
        $this->assertEquals(85, $array['backlink_spam_score']);
    }

    public function test_missing_spam_score_handling(): void
    {
        $dataForSeoItem = [
            'url_from' => 'https:
            'domain_from' => 'example.com',
            'dofollow' => true,

        ];

        $dto = BacklinkDTO::fromArray($dataForSeoItem, 'https:

        $this->assertNull($dto->backlinkSpamScore, 'Spam score should be null when missing');
    }

    public function test_invalid_spam_score_handling(): void
    {
        $dataForSeoItem = [
            'url_from' => 'https:
            'domain_from' => 'example.com',
            'backlink_spam_score' => 'invalid',
            'dofollow' => true,
        ];

        $dto = BacklinkDTO::fromArray($dataForSeoItem, 'https:

        $this->assertNull($dto->backlinkSpamScore, 'Spam score should be null for invalid values');
    }

    public function test_spam_score_range(): void
    {

        $dto1 = BacklinkDTO::fromArray([
            'url_from' => 'https:
            'backlink_spam_score' => 0,
        ], 'https:
        $this->assertEquals(0, $dto1->backlinkSpamScore);

        $dto2 = BacklinkDTO::fromArray([
            'url_from' => 'https:
            'backlink_spam_score' => 100,
        ], 'https:
        $this->assertEquals(100, $dto2->backlinkSpamScore);

        $dto3 = BacklinkDTO::fromArray([
            'url_from' => 'https:
            'backlink_spam_score' => 65,
        ], 'https:
        $this->assertEquals(65, $dto3->backlinkSpamScore);
    }
}
