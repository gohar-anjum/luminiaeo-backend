<?php

namespace Tests\Feature;

use App\DTOs\BacklinkDTO;
use Tests\TestCase;

class BacklinkSpamScoreTest extends TestCase
{
    /**
     * Test that backlink_spam_score is properly extracted from DataForSEO response.
     */
    public function test_spam_score_extraction_from_dataforseo_response(): void
    {
        // Sample DataForSEO response item
        $dataForSeoItem = [
            'type' => 'backlink',
            'domain_from' => 'australianwebdirectory.pro',
            'url_from' => 'https://australianwebdirectory.pro/59e5d533293fb33ae9e52b2ce0f3cdf1-l/',
            'url_to' => 'https://ecomtechagency.com/',
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

        $dto = BacklinkDTO::fromArray($dataForSeoItem, 'https://ecomtechagency.com', 'test-task-id');

        $this->assertNotNull($dto->backlinkSpamScore, 'Spam score should be extracted');
        $this->assertEquals(70, $dto->backlinkSpamScore, 'Spam score should match DataForSEO value');
    }

    /**
     * Test that spam score is included in array output.
     */
    public function test_spam_score_in_array_output(): void
    {
        $dataForSeoItem = [
            'url_from' => 'https://example.com/page',
            'domain_from' => 'example.com',
            'backlink_spam_score' => 85,
            'dofollow' => true,
        ];

        $dto = BacklinkDTO::fromArray($dataForSeoItem, 'https://target.com', 'test-task-id');
        $array = $dto->toArray();

        $this->assertArrayHasKey('backlink_spam_score', $array);
        $this->assertEquals(85, $array['backlink_spam_score']);
    }

    /**
     * Test handling of missing spam score.
     */
    public function test_missing_spam_score_handling(): void
    {
        $dataForSeoItem = [
            'url_from' => 'https://example.com/page',
            'domain_from' => 'example.com',
            'dofollow' => true,
            // No backlink_spam_score field
        ];

        $dto = BacklinkDTO::fromArray($dataForSeoItem, 'https://target.com', 'test-task-id');

        $this->assertNull($dto->backlinkSpamScore, 'Spam score should be null when missing');
    }

    /**
     * Test handling of invalid spam score values.
     */
    public function test_invalid_spam_score_handling(): void
    {
        $dataForSeoItem = [
            'url_from' => 'https://example.com/page',
            'domain_from' => 'example.com',
            'backlink_spam_score' => 'invalid',
            'dofollow' => true,
        ];

        $dto = BacklinkDTO::fromArray($dataForSeoItem, 'https://target.com', 'test-task-id');

        $this->assertNull($dto->backlinkSpamScore, 'Spam score should be null for invalid values');
    }

    /**
     * Test spam score range (0-100).
     */
    public function test_spam_score_range(): void
    {
        // Test minimum value
        $dto1 = BacklinkDTO::fromArray([
            'url_from' => 'https://example.com/page1',
            'backlink_spam_score' => 0,
        ], 'https://target.com', 'test-task-id');
        $this->assertEquals(0, $dto1->backlinkSpamScore);

        // Test maximum value
        $dto2 = BacklinkDTO::fromArray([
            'url_from' => 'https://example.com/page2',
            'backlink_spam_score' => 100,
        ], 'https://target.com', 'test-task-id');
        $this->assertEquals(100, $dto2->backlinkSpamScore);

        // Test typical value
        $dto3 = BacklinkDTO::fromArray([
            'url_from' => 'https://example.com/page3',
            'backlink_spam_score' => 65,
        ], 'https://target.com', 'test-task-id');
        $this->assertEquals(65, $dto3->backlinkSpamScore);
    }
}

