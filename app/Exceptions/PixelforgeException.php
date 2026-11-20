<?php

namespace App\Exceptions;

use Exception;

class PixelforgeException extends Exception
{
    public function __construct(
        string $message,
        protected string $errorCode = 'api_error',
        protected int $httpStatus = 500,
        protected ?string $errorType = null,
        protected ?string $param = null,
        protected ?int $retryAfter = null,
        protected array $details = []
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    public function getParam(): ?string
    {
        return $this->param;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
