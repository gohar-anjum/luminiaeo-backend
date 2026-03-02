<?php

namespace App\Exceptions;

use Exception;

class PageAnalysisException extends Exception
{
    public static function fromClientException(\Illuminate\Http\Client\RequestException $e): self
    {
        $status = $e->response?->status();
        $body = $e->response?->json() ?? [];

        $detail = $body['detail'] ?? $body['message'] ?? $e->getMessage();

        // Map some common error types to cleaner messages
        if ($status === 422) {
            $message = 'The page could not be analyzed. The URL may be invalid or unreachable.';
        } elseif ($status >= 500) {
            $message = 'The page analysis service is currently unavailable. Please try again later.';
        } else {
            $message = (string) $detail;
        }

        return new self($message, $status ?? 500, $e);
    }
}

