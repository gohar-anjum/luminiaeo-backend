<?php

namespace App\Exceptions;

use Exception;

class DataForSEOException extends Exception
{
    protected $statusCode;
    protected $errorCode;

    public function __construct(string $message = "", int $statusCode = 500, string $errorCode = 'DATAFORSEO_ERROR', Exception $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function render($request)
    {
        return response()->json([
            'status' => $this->statusCode,
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->statusCode);
    }
}
