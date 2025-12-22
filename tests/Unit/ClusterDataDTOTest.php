<?php

namespace Tests\Unit;

use App\DTOs\ClusterDataDTO;
use Tests\TestCase;

class ClusterDataDTOTest extends TestCase
{
    public function test_cluster_data_dto_creation(): void
    {
        $dto = new ClusterDataDTO(
            topicName: 'Seo Tools',
            keywordCount: 10,
            suggestedArticleTitles: ['Title 1', 'Title 2'],
            recommendedFaqQuestions: ['Question 1'],
        );

        $this->assertEquals('Seo Tools', $dto->topicName);
        $this->assertEquals(10, $dto->keywordCount);
        $this->assertCount(2, $dto->suggestedArticleTitles);
        $this->assertCount(1, $dto->recommendedFaqQuestions);
    }
}

