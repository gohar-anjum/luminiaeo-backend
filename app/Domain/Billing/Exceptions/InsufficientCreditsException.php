<?php

namespace App\Domain\Billing\Exceptions;

use Exception;

class InsufficientCreditsException extends Exception
{
    protected int $statusCode = 402;

    protected string $errorCode = 'INSUFFICIENT_CREDITS';

    public function __construct(
        string $message = 'Insufficient credits for this action.',
        int $statusCode = 402,
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
