<?php

namespace App\Service\SkinBaron\Exception;

class RateLimitException extends \RuntimeException
{
    public function __construct(
        private readonly int $retryAfterSeconds
    ) {
        parent::__construct("Rate limit exceeded. Retry after {$retryAfterSeconds} seconds.");
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}