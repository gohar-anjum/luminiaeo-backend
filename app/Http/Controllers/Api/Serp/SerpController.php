<?php

namespace App\Http\Controllers\Api\Serp;

use App\Http\Controllers\Controller;
use App\Http\Requests\SerpKeywordDataRequest;
use App\Services\ApiResponseModifier;
use App\Services\Serp\SerpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use App\Exceptions\SerpException;

class SerpController extends Controller
{
    protected SerpService $service;
    protected ApiResponseModifier $responseModifier;

    public function __construct(SerpService $service, ApiResponseModifier $responseModifier)
    {
        $this->service = $service;
        $this->responseModifier = $responseModifier;
    }

    public function keywordData(SerpKeywordDataRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $results = $this->service->getKeywordData(
                $validated['keywords'],
                $validated['language_code'] ?? 'en',
                $validated['location_code'] ?? 2840,
                $validated['options'] ?? []
            );

            $data = array_map(function ($dto) {
                return $dto->toArray();
            }, $results);

            Log::info('Serp keyword data request completed', [
                'keywords_count' => count($validated['keywords']),
                'results_count' => count($data),
            ]);

            return $this->responseModifier
                ->setData($data)
                ->setMessage('Serp keyword data retrieved successfully')
                ->response();
        } catch (InvalidArgumentException $e) {
            Log::warning('Invalid request for Serp keyword data', [
                'error' => $e->getMessage(),
            ]);

            return $this->responseModifier
                ->setMessage($e->getMessage())
                ->setResponseCode(422)
                ->response();
        } catch (SerpException $e) {
            Log::error('Serp API error in keyword data', [
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
                'status_code' => $e->getStatusCode(),
            ]);

            return $this->responseModifier
                ->setMessage($e->getMessage())
                ->setResponseCode($e->getStatusCode())
                ->response();
        }
    }

    public function serpResults(SerpKeywordDataRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            if (count($validated['keywords']) !== 1) {
                return $this->responseModifier
                    ->setMessage('SERP results endpoint requires exactly one keyword')
                    ->setResponseCode(422)
                    ->response();
            }

            $results = $this->service->getSerpResults(
                $validated['keywords'][0],
                $validated['language_code'] ?? 'en',
                $validated['location_code'] ?? 2840,
                $validated['options'] ?? []
            );

            Log::info('Serp results request completed', [
                'keyword' => $validated['keywords'][0],
            ]);

            return $this->responseModifier
                ->setData($results)
                ->setMessage('Serp results retrieved successfully')
                ->response();
        } catch (InvalidArgumentException $e) {
            Log::warning('Invalid request for Serp results', [
                'error' => $e->getMessage(),
            ]);

            return $this->responseModifier
                ->setMessage($e->getMessage())
                ->setResponseCode(422)
                ->response();
        } catch (SerpException $e) {
            Log::error('Serp API error in results', [
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
                'status_code' => $e->getStatusCode(),
            ]);

            return $this->responseModifier
                ->setMessage($e->getMessage())
                ->setResponseCode($e->getStatusCode())
                ->response();
        }
    }
}
