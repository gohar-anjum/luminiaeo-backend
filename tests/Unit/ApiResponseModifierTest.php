<?php

namespace Tests\Unit;

use App\Services\ApiResponseModifier;
use Tests\TestCase;

class ApiResponseModifierTest extends TestCase
{
    protected ApiResponseModifier $modifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modifier = new ApiResponseModifier();
    }

    public function test_set_data_adds_data(): void
    {
        $result = $this->modifier->setData(['test' => 'value']);
        $this->assertInstanceOf(ApiResponseModifier::class, $result);
    }

    public function test_set_message_adds_message(): void
    {
        $result = $this->modifier->setMessage('Test message');
        $this->assertInstanceOf(ApiResponseModifier::class, $result);
    }

    public function test_set_response_code_sets_code(): void
    {
        $result = $this->modifier->setResponseCode(201);
        $this->assertInstanceOf(ApiResponseModifier::class, $result);
    }

    public function test_response_returns_json_response(): void
    {
        $response = $this->modifier
            ->setData(['test' => 'value'])
            ->setMessage('Success')
            ->response();

        $this->assertInstanceOf(\Illuminate\Http\JsonResponse::class, $response);
        $response->assertJson(['status' => 'success']);
    }
}

