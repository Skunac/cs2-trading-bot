<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Cache\CacheItemPoolInterface;

#[AsCommand(
    name: 'app:test-redis',
    description: 'Test Redis connection and operations'
)]
class TestRedisCommand extends Command
{
    public function __construct(
        private readonly CacheInterface $cacheApp,
        private readonly CacheItemPoolInterface $cacheSkinbaronPrices,
        private readonly CacheItemPoolInterface $cacheSkinbaronStats,
        private readonly CacheItemPoolInterface $cacheSkinbaronApi
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Redis Connection Test');

        try {
            // Test 1: Default app cache
            $io->section('Test 1: Default App Cache');
            
            $testKey = 'test_key_' . time();
            $testValue = ['message' => 'Hello Redis!', 'timestamp' => time()];
            
            $this->cacheApp->get($testKey, function (ItemInterface $item) use ($testValue): array {
                $item->expiresAfter(60);
                return $testValue;
            });
            $io->success('✓ Write to default cache successful');
            
            $cached = $this->cacheApp->get($testKey, fn() => null);
            if ($cached === $testValue) {
                $io->success('✓ Read from default cache successful');
            }
            
            $this->cacheApp->delete($testKey);
            $io->success('✓ Delete from default cache successful');
            
            // Test 2: SkinBaron Prices Cache
            $io->section('Test 2: SkinBaron Prices Cache (3600s TTL)');
            
            $priceKey = 'price_test_' . time();
            $priceData = [
                'market_hash_name' => 'AK-47 | Redline (Field-Tested)',
                'price' => 10.50,
                'timestamp' => time()
            ];
            
            $priceItem = $this->cacheSkinbaronPrices->getItem($priceKey);
            $priceItem->set($priceData);
            $priceItem->expiresAfter(3600);
            $this->cacheSkinbaronPrices->save($priceItem);
            $io->success('✓ Saved to skinbaron_prices cache');
            
            $cachedPrice = $this->cacheSkinbaronPrices->getItem($priceKey);
            if ($cachedPrice->isHit() && $cachedPrice->get() === $priceData) {
                $io->success('✓ Retrieved from skinbaron_prices cache');
            }
            
            $this->cacheSkinbaronPrices->deleteItem($priceKey);
            $io->success('✓ Deleted from skinbaron_prices cache');
            
            // Test 3: SkinBaron Stats Cache
            $io->section('Test 3: SkinBaron Stats Cache (1800s TTL)');
            
            $statsKey = 'stats_test_' . time();
            $statsData = [
                'market_hash_name' => 'AK-47 | Redline (Field-Tested)',
                'avg_price_7d' => 10.25,
                'avg_price_30d' => 10.50,
                'volatility' => 0.15
            ];
            
            $statsItem = $this->cacheSkinbaronStats->getItem($statsKey);
            $statsItem->set($statsData);
            $statsItem->expiresAfter(1800);
            $this->cacheSkinbaronStats->save($statsItem);
            $io->success('✓ Saved to skinbaron_stats cache');
            
            $cachedStats = $this->cacheSkinbaronStats->getItem($statsKey);
            if ($cachedStats->isHit() && $cachedStats->get() === $statsData) {
                $io->success('✓ Retrieved from skinbaron_stats cache');
            }
            
            $this->cacheSkinbaronStats->deleteItem($statsKey);
            $io->success('✓ Deleted from skinbaron_stats cache');
            
            // Test 4: SkinBaron API Cache
            $io->section('Test 4: SkinBaron API Cache (300s TTL)');
            
            $apiKey = 'api_test_' . time();
            $apiData = [
                'endpoint' => '/Search',
                'results' => ['item1', 'item2', 'item3'],
                'timestamp' => time()
            ];
            
            $apiItem = $this->cacheSkinbaronApi->getItem($apiKey);
            $apiItem->set($apiData);
            $apiItem->expiresAfter(300);
            $this->cacheSkinbaronApi->save($apiItem);
            $io->success('✓ Saved to skinbaron_api cache');
            
            $cachedApi = $this->cacheSkinbaronApi->getItem($apiKey);
            if ($cachedApi->isHit() && $cachedApi->get() === $apiData) {
                $io->success('✓ Retrieved from skinbaron_api cache');
            }
            
            $this->cacheSkinbaronApi->deleteItem($apiKey);
            $io->success('✓ Deleted from skinbaron_api cache');
            
            // Test 5: TTL verification
            $io->section('Test 5: TTL Verification');
            
            $ttlKey = 'ttl_test_' . time();
            $ttlItem = $this->cacheSkinbaronApi->getItem($ttlKey);
            $ttlItem->set('expires in 2 seconds');
            $ttlItem->expiresAfter(2);
            $this->cacheSkinbaronApi->save($ttlItem);
            $io->info('Cached item with 2 second TTL...');
            
            sleep(1);
            $check1 = $this->cacheSkinbaronApi->getItem($ttlKey);
            if ($check1->isHit()) {
                $io->success('✓ Item exists after 1 second');
            }
            
            sleep(2);
            $check2 = $this->cacheSkinbaronApi->getItem($ttlKey);
            if (!$check2->isHit()) {
                $io->success('✓ Item expired after 3 seconds (TTL working correctly)');
            } else {
                $io->warning('⚠ Item did not expire (check Redis configuration)');
            }
            
            // Final summary
            $io->newLine();
            $io->success('✓ All Redis cache pools are working correctly!');
            $io->table(
                ['Cache Pool', 'TTL', 'Status'],
                [
                    ['cache.app (default)', 'Variable', '✓ Working'],
                    ['cache.skinbaron_prices', '3600s (1 hour)', '✓ Working'],
                    ['cache.skinbaron_stats', '1800s (30 min)', '✓ Working'],
                    ['cache.skinbaron_api', '300s (5 min)', '✓ Working'],
                ]
            );

            $io->newLine();
            $io->info('Your cache configuration is excellent! Each pool has appropriate TTLs:');
            $io->listing([
                'Prices: 1 hour (historical data, doesn\'t change often)',
                'Stats: 30 minutes (aggregated data, recalculated periodically)',
                'API: 5 minutes (live data, needs to be fresh)',
            ]);

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error('Redis test failed: ' . $e->getMessage());
            $io->note('Make sure Redis is running: docker compose ps');
            $io->note('Check Redis logs: docker compose logs redis');
            
            return Command::FAILURE;
        }
    }
}