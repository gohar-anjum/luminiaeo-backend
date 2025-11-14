<?php

namespace App\Http\Controllers\Api\DataForSEO;

use App\Exceptions\DataForSEOException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchVolumeRequest;
use App\Services\ApiResponseModifier;
use App\Services\DataForSEO\DataForSEOService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class DataForSEOController extends Controller
{
    protected DataForSEOService $service;
    protected ApiResponseModifier $responseModifier;

    public function __construct(DataForSEOService $service, ApiResponseModifier $responseModifier)
    {
        $this->service = $service;
        $this->responseModifier = $responseModifier;
    }

    /**
     * Get search volume for keywords
     *
     * @param SearchVolumeRequest $request
     * @return JsonResponse
     */
    public function searchVolume(SearchVolumeRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Get search volume data
            $results = $this->service->getSearchVolume(
                $validated['keywords'],
                $validated['language_code'],
                $validated['location_code']
            );

            // Convert DTOs to arrays for response
            $data = array_map(function ($dto) {
                return $dto->toArray();
            }, $results);

            Log::info('Search volume request completed', [
                'keywords_count' => count($validated['keywords']),
                'results_count' => count($data),
            ]);

            return $this->responseModifier
                ->setData($data)
                ->setMessage('Search volume data retrieved successfully')
                ->response();
        } catch (InvalidArgumentException $e) {
            Log::warning('Invalid request for search volume', [
                'error' => $e->getMessage(),
            ]);

            return $this->responseModifier
                ->setMessage($e->getMessage())
                ->setResponseCode(422)
                ->response();
        } catch (DataForSEOException $e) {
            Log::error('DataForSEO error in search volume', [
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode(),
            ]);

            return $this->responseModifier
                ->setMessage($e->getMessage())
                ->setResponseCode($e->getStatusCode())
                ->response();
        } catch (\Exception $e) {
            Log::error('Unexpected error in search volume', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->responseModifier
                ->setMessage('An unexpected error occurred')
                ->setResponseCode(500)
                ->response();
        }
    }
}
