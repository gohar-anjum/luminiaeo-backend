<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FaqGenerationRequest;
use App\Services\ApiResponseModifier;
use App\Services\FAQ\FaqGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class FaqController extends Controller
{
    protected FaqGeneratorService $faqService;
    protected ApiResponseModifier $responseModifier;

    public function __construct(FaqGeneratorService $faqService, ApiResponseModifier $responseModifier)
    {
        $this->faqService = $faqService;
        $this->responseModifier = $responseModifier;
    }

    public function generate(FaqGenerationRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $responseDTO = $this->faqService->generateFaqs(
                $validated['input'],
                $validated['options'] ?? []
            );

            Log::info('FAQ generation completed', [
                'input' => $validated['input'],
                'faqs_count' => $responseDTO->count,
                'from_database' => $responseDTO->fromDatabase,
                'api_calls_saved' => $responseDTO->apiCallsSaved,
            ]);

            return $this->responseModifier
                ->setData($responseDTO->toArray())
                ->setMessage($responseDTO->fromDatabase ? 'FAQs retrieved from database' : 'FAQs generated successfully')
                ->response();

        } catch (\InvalidArgumentException $e) {
            Log::warning('Invalid FAQ generation request', [
                'error' => $e->getMessage(),
            ]);

            return $this->responseModifier
                ->setMessage($e->getMessage())
                ->setResponseCode(422)
                ->response();

        } catch (\RuntimeException $e) {
            Log::error('FAQ generation error', [
                'error' => $e->getMessage(),
                'input' => $request->input('input'),
            ]);

            return $this->responseModifier
                ->setMessage('Failed to generate FAQs: ' . $e->getMessage())
                ->setResponseCode(500)
                ->response();

        } catch (\Exception $e) {
            Log::error('Unexpected error in FAQ generation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->responseModifier
                ->setMessage('An unexpected error occurred while generating FAQs')
                ->setResponseCode(500)
                ->response();
        }
    }
}
