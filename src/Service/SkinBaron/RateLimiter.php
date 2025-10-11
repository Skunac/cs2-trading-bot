<?php

namespace App\Service\SkinBaron;

use App\Service\SkinBaron\Exception\RateLimitException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class RateLimiter
{
    private const RATE_LIMIT_KEY = 'skinbaron_rate_limit';
    private const MAX_REQUESTS_PER_MINUTE = 30; // Adjust based on API docs
    private const WINDOW_SECONDS = 60;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function waitIfNeeded(): void
    {
        $requests = $this->cache->get(self::RATE_LIMIT_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::WINDOW_SECONDS);
            return ['count' => 0, 'window_start' => time()];
        });

        $now = time();
        $windowStart = $requests['window_start'];
        $count = $requests['count'];

        // Reset window if expired
        if ($now - $windowStart >= self::WINDOW_SECONDS) {
            $this->cache->delete(self::RATE_LIMIT_KEY);
            return;
        }

        // Check if we've hit the limit
        if ($count >= self::MAX_REQUESTS_PER_MINUTE) {
            $retryAfter = self::WINDOW_SECONDS - ($now - $windowStart);
            $this->logger->warning('Rate limit hit, waiting', ['retry_after' => $retryAfter]);
            
            throw new RateLimitException($retryAfter);
        }

        // Increment counter
        $requests['count']++;
        $this->cache->get(self::RATE_LIMIT_KEY, function (ItemInterface $item) use ($requests): array {
            $item->expiresAfter(self::WINDOW_SECONDS);
            return $requests;
        });

        // Add small delay between requests (100ms)
        usleep(100000);
    }
}