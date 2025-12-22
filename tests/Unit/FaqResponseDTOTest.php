<?php

namespace Tests\Unit;

use App\DTOs\FaqDataDTO;
use App\DTOs\FaqResponseDTO;
use Tests\TestCase;

class FaqResponseDTOTest extends TestCase
{
    public function test_faq_response_dto_creation(): void
    {
        $faqs = [
            new FaqDataDTO(question: 'Q1?', answer: 'A1'),
            new FaqDataDTO(question: 'Q2?', answer: 'A2'),
        ];

        $dto = new FaqResponseDTO(
            faqs: $faqs,
            totalCount: 2,
            source: 'api',
        );

        $this->assertCount(2, $dto->faqs);
        $this->assertEquals(2, $dto->totalCount);
        $this->assertEquals('api', $dto->source);
    }
}

