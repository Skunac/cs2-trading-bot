<?php

namespace App\Service\SkinBaron\Exception;

class SkinBaronApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly string|array|null $responseData = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseData(): string|array|null
    {
        return $this->responseData;
    }
}