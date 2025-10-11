<?php

namespace App\Service\SkinBaron\Exception;

class CircuitBreakerOpenException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Circuit breaker is open. Too many API failures detected.');
    }
}