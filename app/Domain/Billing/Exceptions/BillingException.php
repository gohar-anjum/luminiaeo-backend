<?php

namespace App\Domain\Billing\Exceptions;

use Exception;

class BillingException extends Exception
{
    protected int $statusCode = 400;

    protected string $errorCode = 'BILLING_ERROR';

    public function __construct(
        string $message = 'A billing error occurred.',
        int $statusCode = 400,
        string $errorCode = 'BILLING_ERROR',
        ?Exception $previous = null
    ) {
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
}
