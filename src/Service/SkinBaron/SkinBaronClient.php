<?php

namespace App\Service\SkinBaron;

use App\Service\SkinBaron\Exception\CircuitBreakerOpenException;
use App\Service\SkinBaron\Exception\RateLimitException;
use App\Service\SkinBaron\Exception\SkinBaronApiException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SkinBaronClient
{
    private const BASE_URL = 'https://api.skinbaron.de';
    private const CACHE_TTL_PRICES = 300; // 5 minutes
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 1000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RateLimiter $rateLimiter,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey
    ) {
    }

    /**
     * Get extended price list (all items with prices)
     * 
     * @param int $appId Game app ID (730 = CS2/CSGO, 440 = TF2, etc.)
     * @param array $filters Optional filters: ['itemName' => true, 'statTrak' => true, 'souvenir' => true, 'dopplerPhase' => 'string']
     */
    public function getExtendedPriceList(int $appId = 730, array $filters = []): array
    {
        $cacheKey = 'skinbaron_price_list_' . $appId . '_' . md5(json_encode($filters));
        
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($appId, $filters): array {
            $item->expiresAfter(self::CACHE_TTL_PRICES);
            
            // Build parameters according to API spec
            $params = array_merge([
                'appId' => $appId,       // Required: Game app ID
                'itemName' => true,      // Include item names
                'statTrak' => true,      // Include StatTrak items
                'souvenir' => true,      // Include Souvenir items
            ], $filters);
            
            $data = $this->makeRequest('/GetExtendedPriceList', $params);
            
            // Check for errors
            if (isset($data['errors'])) {
                throw new SkinBaronApiException('API returned errors: ' . json_encode($data['errors']));
            }
            
            if (!isset($data['map'])) {
                throw new SkinBaronApiException('Invalid response: missing sales data', 0, $data);
            }

            return $data['map'];
        });
    }

    /**
     * Get sales history for a specific item (last 30 days)
     * 
     * @param string $itemName The item name to search for (e.g., "AK-47 | Redline (Field-Tested)")
     * @param bool|null $statTrak Filter for StatTrak items (optional)
     * @param bool|null $souvenir Filter for Souvenir items (optional)
     * @param string|null $dopplerPhase Filter for specific Doppler phase (optional)
     * @return array Array of sale records with price, wear, dateSold, etc.
     */
    public function getNewestSales30Days(
        string $itemName
    ): array {
        // Build cache key including all parameters
        $cacheKey = 'skinbaron_sales_30d_' . md5($itemName);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use (
            $itemName
        ): array {
            // Cache for 1 hour - historical sales don't change frequently
            $item->expiresAfter(3600);

            // Build request parameters according to API spec
            $params = [
                'itemName' => $itemName  // REQUIRED parameter
            ];

            $this->logger->debug('Fetching 30-day sales history', [
                'item' => $itemName,
                'filters' => array_filter($params, fn($k) => $k !== 'itemName', ARRAY_FILTER_USE_KEY)
            ]);

            $data = $this->makeRequest('/GetNewestSales30Days', $params);

            // API returns data wrapped in 'newestSales30Days' key
            if (isset($data['newestSales30Days']) && is_array($data['newestSales30Days'])) {
                $this->logger->info('Retrieved sales history', [
                    'item' => $itemName,
                    'sales_count' => count($data['newestSales30Days'])
                ]);
                
                return $data['newestSales30Days'];
            }

            // Fallback: if structure is different, log and return empty
            $this->logger->warning('Unexpected response format for GetNewestSales30Days', [
                'item' => $itemName,
                'response_keys' => array_keys($data)
            ]);
            
            return [];
        });
    }

    /**
     * Search for specific item
     */
    public function search(string $marketHashName, int $limit = 50, array $filters = []): array
    {
        $cacheKey = 'skinbaron_search_' . md5($marketHashName . json_encode($filters));
        $marketHashName = 'AK-47 | Redline';
        // TODO extract the condition, the stattrak and the name and map the parameters
        
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($marketHashName, $limit, $filters): array {
            $item->expiresAfter(60); // 1 minute cache for searches
            
            // Build search parameters according to API spec
            $params = array_merge([
                'search_item' => $marketHashName,
                'items_per_page' => $limit,
                'appid' => 730, // CS2/CSGO app ID,
                'minWear' => 0.0,
                'maxWear' => 1.0,
                'statTrak' => false
            ], $filters);
            
            $data = $this->makeRequest('/Search', $params);
            dd($data);

            return $data['sales'] ?? [];
        });
    } 

    /**
     * Purchase items by sale IDs
     */
    public function buyItems(array $saleIds): array
    {
        if (empty($saleIds)) {
            throw new \InvalidArgumentException('Sale IDs cannot be empty');
        }

        $this->logger->info('Attempting to purchase items', [
            'sale_ids' => $saleIds,
            'count' => count($saleIds)
        ]);

        $data = $this->makeRequest('/BuyItems', [
            'saleids' => implode(',', $saleIds)
        ]);

        if (!isset($data['itemsBought'])) {
            throw new SkinBaronApiException('Invalid buy response', 0, $data);
        }

        $this->logger->info('Purchase completed', [
            'items_bought' => $data['itemsBought'],
            'balance' => $data['balance'] ?? 'unknown'
        ]);

        return $data;
    }

    /**
     * Get user's SkinBaron inventory (items we own but haven't listed yet)
     */
    public function getInventory(): array
    {
        $data = $this->makeRequest('/GetInventory');
        return $data['inventory'] ?? [];
    }

    /**
     * List items for sale
     */
    public function listItems(array $items): array
    {
        // Format: [['itemid' => 123, 'price' => 10.50], ...]
        if (empty($items)) {
            throw new \InvalidArgumentException('Items cannot be empty');
        }

        $this->logger->info('Listing items for sale', [
            'count' => count($items),
            'items' => $items
        ]);

        $data = $this->makeRequest('/ListItems', [
            'items' => $items
        ]);

        $this->logger->info('Items listed', ['response' => $data]);

        return $data;
    }

    /**
     * Edit prices of already listed items
     */
    public function editPriceMulti(array $priceUpdates): array
    {
        // Format: [['saleid' => 123, 'price' => 11.00], ...]
        if (empty($priceUpdates)) {
            throw new \InvalidArgumentException('Price updates cannot be empty');
        }

        $this->logger->info('Updating listing prices', [
            'count' => count($priceUpdates)
        ]);

        $data = $this->makeRequest('/EditPriceMulti', [
            'items' => $priceUpdates
        ]);

        return $data;
    }

    /**
     * Get sold items (sales history)
     */
    public function getSales(int $page = 1): array
    {
        $data = $this->makeRequest('/GetMySales', ['page' => $page]);
        return $data['sales'] ?? [];
    }

    /**
     * Get current balance
     */
    public function getBalance(): float
    {
        $data = $this->makeRequest('/GetBalance');
        
        if (!isset($data['balance'])) {
            throw new SkinBaronApiException('Invalid balance response', 0, $data);
        }

        return (float) $data['balance'];
    }

    /**
     * Make HTTP request with retry logic, rate limiting, and circuit breaker
     * All SkinBaron API calls are POST with JSON body
     */
    private function makeRequest(string $endpoint, array $params = []): array
    {
        // Check circuit breaker
        $this->circuitBreaker->checkAndThrow();

        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                // Rate limiting
                $this->rateLimiter->waitIfNeeded();

                // Build request body - always include API key
                $body = array_merge(['apikey' => $this->apiKey], $params);

                // All requests are POST with JSON body
                $options = [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-requested-with' => 'XMLHttpRequest',
                    ],
                    'json' => $body
                ];

                $url = self::BASE_URL . $endpoint;

                $this->logger->debug('Making API request', [
                    'endpoint' => $endpoint,
                    'has_params' => !empty($params)
                ]);

                // Execute request (always POST)
                $response = $this->httpClient->request('POST', $url, $options);
                $statusCode = $response->getStatusCode();
                
                // Get raw content first for debugging
                $rawContent = $response->getContent(false);
                
                // Try to parse as JSON
                try {
                    $content = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    $this->logger->error('Failed to parse JSON response', [
                        'endpoint' => $endpoint,
                        'status' => $statusCode,
                        'raw_content' => substr($rawContent, 0, 500),
                        'error' => $e->getMessage()
                    ]);
                    throw new SkinBaronApiException(
                        'Invalid JSON response: ' . $e->getMessage(),
                        $statusCode,
                        ['raw' => $rawContent]
                    );
                }

                // Check for API-level errors
                if (isset($content['error'])) {
                    throw new SkinBaronApiException(
                        $content['error'],
                        $statusCode,
                        $content
                    );
                }

                // Success
                if ($statusCode >= 200 && $statusCode < 300) {
                    $this->circuitBreaker->recordSuccess();
                    return $content;
                }

                // Unexpected status code
                throw new SkinBaronApiException(
                    'Unexpected status code',
                    $statusCode,
                    $content
                );

            } catch (RateLimitException $e) {
                // Rate limit hit - wait and retry
                $this->logger->warning('Rate limit hit, waiting', [
                    'retry_after' => $e->getRetryAfterSeconds()
                ]);
                sleep($e->getRetryAfterSeconds());
                continue;

            } catch (CircuitBreakerOpenException $e) {
                // Circuit breaker open - don't retry
                $this->logger->error('Circuit breaker open, aborting request');
                throw $e;

            } catch (SkinBaronApiException $e) {
                // API returned an error - log and potentially retry
                $attempt++;
                $lastException = $e;

                $this->logger->error('API error response', [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'status' => $e->getStatusCode()
                ]);

                // Don't retry on authentication errors (4xx)
                if ($e->getStatusCode() >= 400 && $e->getStatusCode() < 500) {
                    throw $e;
                }

                if ($attempt >= self::MAX_RETRIES) {
                    $this->circuitBreaker->recordFailure();
                    break;
                }

                usleep(self::RETRY_DELAY_MS * 1000 * $attempt);

            } catch (\Throwable $e) {
                $attempt++;
                $lastException = $e;

                $this->logger->error('API request failed', [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e)
                ]);

                if ($attempt >= self::MAX_RETRIES) {
                    $this->circuitBreaker->recordFailure();
                    break;
                }

                // Exponential backoff
                usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
            }
        }

        // All retries exhausted
        throw new SkinBaronApiException(
            'API request failed after ' . self::MAX_RETRIES . ' attempts: ' . ($lastException?->getMessage() ?? 'Unknown error'),
            0,
            null,
            $lastException
        );
    }
}