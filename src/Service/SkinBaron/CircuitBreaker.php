<?php

namespace App\Service\SkinBaron;

use App\Service\SkinBaron\Exception\CircuitBreakerOpenException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class CircuitBreaker
{
    private const CACHE_KEY = 'skinbaron_circuit_breaker';
    private const FAILURE_THRESHOLD = 10;
    private const RECOVERY_TIMEOUT = 300; // 5 minutes
    private const HALF_OPEN_MAX_ATTEMPTS = 1;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isOpen(): bool
    {
        $state = $this->getState();
        
        if ($state['status'] === 'open') {
            // Check if recovery timeout has passed
            if (time() - $state['opened_at'] >= self::RECOVERY_TIMEOUT) {
                $this->transitionToHalfOpen();
                return false;
            }
            return true;
        }

        return false;
    }

    public function recordSuccess(): void
    {
        $state = $this->getState();

        if ($state['status'] === 'half_open') {
            $this->logger->info('Circuit breaker: Half-open test succeeded, closing circuit');
            $this->close();
        } elseif ($state['status'] === 'closed') {
            // Reset failure count on success
            $this->updateState(['failures' => 0, 'status' => 'closed']);
        }
    }

    public function recordFailure(): void
    {
        $state = $this->getState();

        if ($state['status'] === 'half_open') {
            $this->logger->warning('Circuit breaker: Half-open test failed, reopening circuit');
            $this->open();
            return;
        }

        $failures = ($state['failures'] ?? 0) + 1;
        $this->updateState(['failures' => $failures, 'status' => 'closed']);

        if ($failures >= self::FAILURE_THRESHOLD) {
            $this->open();
        }
    }

    private function open(): void
    {
        $this->logger->error('Circuit breaker: Opening circuit due to failures', [
            'threshold' => self::FAILURE_THRESHOLD
        ]);

        $this->updateState([
            'status' => 'open',
            'opened_at' => time(),
            'failures' => 0
        ]);
    }

    private function close(): void
    {
        $this->updateState([
            'status' => 'closed',
            'failures' => 0,
            'opened_at' => null
        ]);
    }

    private function transitionToHalfOpen(): void
    {
        $this->logger->info('Circuit breaker: Transitioning to half-open, testing recovery');
        
        $this->updateState([
            'status' => 'half_open',
            'failures' => 0,
            'opened_at' => null
        ]);
    }

    private function getState(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(86400); // 24 hours
            return ['status' => 'closed', 'failures' => 0, 'opened_at' => null];
        });
    }

    private function updateState(array $state): void
    {
        $this->cache->delete(self::CACHE_KEY);
        $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) use ($state): array {
            $item->expiresAfter(86400);
            return $state;
        });
    }

    public function checkAndThrow(): void
    {
        if ($this->isOpen()) {
            throw new CircuitBreakerOpenException();
        }
    }
}