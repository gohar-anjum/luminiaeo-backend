<?php

namespace App\Domain\Billing\Exceptions;

use Exception;

class FeatureNotActiveException extends Exception
{
    protected int $statusCode = 400;

    protected string $errorCode = 'FEATURE_NOT_ACTIVE';

    public function __construct(
        string $message = 'This feature is not available.',
        int $statusCode = 400,
        ?Exception $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
