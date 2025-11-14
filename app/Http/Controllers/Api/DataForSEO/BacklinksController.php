<?php

namespace App\Http\Controllers\Api\DataForSEO;

use App\Exceptions\DataForSEOException;
use App\Exceptions\PbnDetectorException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BacklinksHarmfulRequest;
use App\Http\Requests\BacklinksResultsRequest;
use App\Http\Requests\BacklinksSubmitRequest;
use App\Interfaces\DataForSEO\BacklinksRepositoryInterface;
use App\Services\ApiResponseModifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class BacklinksController extends Controller
{
    protected BacklinksRepositoryInterface $repository;
    protected ApiResponseModifier $responseModifier;

    public function __construct(BacklinksRepositoryInterface $repository, ApiResponseModifier $responseModifier)
    {
        $this->repository = $repository;
        $this->responseModifier = $responseModifier;
    }

    /**
     * Submit backlinks retrieval task
     *
     * @param BacklinksSubmitRequest $request
     * @return JsonResponse
     */
    public function submit(BacklinksSubmitRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Create task and receive results immediately (live endpoint)
            $seoTask = $this->repository->createTask(
                $validated['domain'],
                $validated['limit'] ?? 100
            );

            $payload = $seoTask->result ?? [];
            $summary = $payload['summary'] ?? [];
            $pbnDetection = $payload['pbn_detection'] ?? [];
            $backlinks = $payload['backlinks']['items'] ?? $payload['backlinks'] ?? [];


            return $this->responseModifier
                ->setData([
                    'task_id' => $seoTask->task_id,
                    'domain' => $seoTask->domain,
                    'status' => $seoTask->status,
                    'submitted_at' => $seoTask->submitted_at,
                    'completed_at' => $seoTask->completed_at,
                    'backlinks' => $backlinks,
                    'summary' => $summary,
                    'pbn_detection' => $pbnDetection,
                ])
                ->setMessage('Backlinks retrieved successfully')
                ->response();
        } catch (PbnDetectorException $e) {
            Log::error('PBN detector error', ['error' => $e->getMessage()]);
            return $this->responseModifier->setMessage($e->getMessage())->setResponseCode(502)->response();
        } catch (DataForSEOException $e) {
            Log::error('DataForSEO error', ['error' => $e->getMessage(), 'code' => $e->getErrorCode()]);
            return $this->responseModifier->setMessage($e->getMessage())->setResponseCode($e->getStatusCode())->response();
        } catch (\Exception $e) {
            Log::error('Unexpected error in backlinks submit', ['error' => $e->getMessage()]);
            return $this->responseModifier->setMessage('An unexpected error occurred')->setResponseCode(500)->response();
        }
    }

    public function results(BacklinksResultsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Get task status
            $seoTask = $this->repository->getTaskStatus($validated['task_id']);

            if (!$seoTask) {
                return $this->responseModifier
                    ->setMessage('Task not found')
                    ->setResponseCode(404)
                    ->response();
            }

            $payload = $seoTask->result ?? [];

            return $this->responseModifier
                ->setData([
                    'task_id' => $seoTask->task_id,
                    'status' => $seoTask->status,
                    'results' => $payload,
                    'completed_at' => $seoTask->completed_at,
                ])
                ->setMessage('Backlinks results retrieved successfully')
                ->response();
        } catch (DataForSEOException $e) {
            Log::error('DataForSEO error', ['error' => $e->getMessage()]);
            return $this->responseModifier->setMessage($e->getMessage())->setResponseCode($e->getStatusCode())->response();
        } catch (\Exception $e) {
            Log::error('Unexpected error', ['error' => $e->getMessage()]);
            return $this->responseModifier->setMessage('An unexpected error occurred')->setResponseCode(500)->response();
        }
    }

    public function status(BacklinksResultsRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $seoTask = $this->repository->getTaskStatus($validated['task_id']);

            if (!$seoTask) {
                return $this->responseModifier
                    ->setMessage('Task not found')
                    ->setResponseCode(404)
                    ->response();
            }

            return $this->responseModifier
                ->setData([
                    'task_id' => $seoTask->task_id,
                    'domain' => $seoTask->domain,
                    'status' => $seoTask->status,
                    'retry_count' => $seoTask->retry_count,
                    'submitted_at' => $seoTask->submitted_at,
                    'completed_at' => $seoTask->completed_at,
                    'failed_at' => $seoTask->failed_at,
                    'error_message' => $seoTask->error_message,
                ])
                ->setMessage('Task status retrieved successfully')
                ->response();
        } catch (\Exception $e) {
            Log::error('Unexpected error', ['error' => $e->getMessage()]);
            return $this->responseModifier->setMessage('An unexpected error occurred')->setResponseCode(500)->response();
        }
    }

    public function harmful(BacklinksHarmfulRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $riskLevels = $validated['risk_levels'] ?? ['high', 'critical'];
        $backlinks = $this->repository->getHarmfulBacklinks(
            $validated['domain'],
            $riskLevels
        );

        return $this->responseModifier
            ->setData([
                'domain' => $validated['domain'],
                'risk_levels' => $riskLevels,
                'count' => count($backlinks),
                'backlinks' => $backlinks,
            ])
            ->setMessage('Harmful backlinks retrieved successfully')
            ->response();
    }
}
