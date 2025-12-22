<?php

namespace App\Traits;

use App\Services\ApiResponseModifier;
use Illuminate\Http\JsonResponse;

trait HasApiResponse
{
    protected function apiResponse(): ApiResponseModifier
    {
        return app(ApiResponseModifier::class);
    }

    protected function successResponse($data = null, string $message = 'Success', int $code = 200): JsonResponse
    {
        return $this->apiResponse()
            ->setData($data)
            ->setMessage($message)
            ->setResponseCode($code)
            ->response();
    }

    protected function errorResponse(string $message = 'Error', int $code = 400, $errors = null): JsonResponse
    {
        $response = $this->apiResponse()
            ->setMessage($message)
            ->setResponseCode($code);

        if ($errors) {
            $response->setData(['errors' => $errors]);
        }

        return $response->response();
    }

    protected function notFoundResponse(string $message = 'Resource not found'): JsonResponse
    {
        return $this->errorResponse($message, 404);
    }

    protected function unauthorizedResponse(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->errorResponse($message, 403);
    }
}
