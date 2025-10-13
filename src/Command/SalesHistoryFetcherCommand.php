<?php

namespace App\Command;

use App\Repository\SaleHistoryRepository;
use App\Repository\WhitelistedItemRepository;
use App\Service\SkinBaron\SkinBaronClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sales-history:fetch',
    description: 'Fetch historical sales data for whitelisted items from SkinBaron API'
)]
class SalesHistoryFetcherCommand extends Command
{
    private const RETENTION_DAYS = 90;
    private const DELAY_BETWEEN_ITEMS_MS = 100000; // 100ms

    public function __construct(
        private readonly SkinBaronClient $skinBaronClient,
        private readonly WhitelistedItemRepository $whitelistRepo,
        private readonly SaleHistoryRepository $saleHistoryRepo,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Clean up old sales data (90+ days)')
            ->addOption('item', null, InputOption::VALUE_OPTIONAL, 'Fetch specific item only')
            ->addOption('no-delay', null, InputOption::VALUE_NONE, 'Skip delay between API calls (use carefully)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startTime = microtime(true);

        $io->title('CS2 Trading Bot - Sales History Fetcher');
        $io->info('Fetching historical sales data from SkinBaron API');

        // Cleanup old data if requested
        if ($input->getOption('cleanup')) {
            $io->section('Cleaning up old sales data');
            $deleted = $this->cleanupOldData();
            $io->success("Deleted {$deleted} old sales records (older than " . self::RETENTION_DAYS . " days)");
        }

        // Load whitelisted items
        $specificItem = $input->getOption('item');
        if ($specificItem) {
            $item = $this->whitelistRepo->findOneBy([
                'marketHashName' => $specificItem,
                'isActive' => true,
            ]);
            
            if (!$item) {
                $io->error("Item '{$specificItem}' not found in whitelist or not active");
                return Command::FAILURE;
            }
            
            $whitelistedItems = [$item];
        } else {
            $whitelistedItems = $this->whitelistRepo->findBy(['isActive' => true]);
        }

        if (empty($whitelistedItems)) {
            $io->warning('No active whitelisted items found. Please populate the whitelist first.');
            return Command::FAILURE;
        }

        $io->info('Found ' . count($whitelistedItems) . ' active whitelisted items');

        // Process each item
        $totalSalesInserted = 0;
        $totalItemsProcessed = 0;
        $totalItemsWithNoData = 0;
        $errors = [];

        $progressBar = $io->createProgressBar(count($whitelistedItems));
        $progressBar->setFormat('verbose');
        $progressBar->start();

        foreach ($whitelistedItems as $item) {
            try {
                $salesInserted = $this->fetchAndStoreSalesForItem($item->getMarketHashName());

                if ($salesInserted === 0) {
                    $totalItemsWithNoData++;
                } else {
                    $totalSalesInserted += $salesInserted;
                }

                $totalItemsProcessed++;

                $this->logger->info('Fetched sales for item', [
                    'market_hash_name' => $item->getMarketHashName(),
                    'sales_inserted' => $salesInserted,
                ]);

            } catch (\Exception $e) {
                $errorMsg = sprintf(
                    '%s: %s',
                    $item->getMarketHashName(),
                    $e->getMessage()
                );
                $errors[] = $errorMsg;

                $this->logger->error('Failed to fetch sales for item', [
                    'market_hash_name' => $item->getMarketHashName(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $progressBar->advance();

            // Delay to respect rate limits (unless disabled)
            if (!$input->getOption('no-delay')) {
                usleep(self::DELAY_BETWEEN_ITEMS_MS);
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        // Summary
        $duration = $this->formatDuration($startTime);
        
        $io->section('Summary');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Items Processed', $totalItemsProcessed],
                ['Items With Sales Data', $totalItemsProcessed - $totalItemsWithNoData],
                ['Items With No Data', $totalItemsWithNoData],
                ['Total Sales Inserted', $totalSalesInserted],
                ['Avg Sales Per Item', $totalItemsProcessed > 0 ? round($totalSalesInserted / $totalItemsProcessed, 1) : 0],
                ['Errors', count($errors)],
                ['Duration', $duration],
            ]
        );

        if (!empty($errors)) {
            $io->warning('Errors encountered:');
            $io->listing(array_slice($errors, 0, 10)); // Show first 10 errors
            
            if (count($errors) > 10) {
                $io->note('... and ' . (count($errors) - 10) . ' more errors. Check logs for details.');
            }
        }

        if ($totalItemsWithNoData > 0) {
            $io->note("Items with no sales data may be low-volume or unlisted. Consider removing from whitelist.");
        }

        if ($totalItemsProcessed === 0) {
            return Command::FAILURE;
        }

        $io->success('Sales history fetch completed!');
        
        return Command::SUCCESS;
    }

    /**
     * Fetch sales data from API and store using repository's bulk insert
     */
    private function fetchAndStoreSalesForItem(string $marketHashName): int
    {
        // Call API to get sales (last 30 days)
        $salesData = $this->skinBaronClient->getNewestSales30Days(
            itemName: $marketHashName
        );

        if (empty($salesData)) {
            $this->logger->warning('No sales data returned from API', [
                'market_hash_name' => $marketHashName,
            ]);
            return 0;
        }

        // Transform API response to format expected by repository
        $salesToInsert = [];
        
        foreach ($salesData as $sale) {
            // Validate required fields
            if (!isset($sale['dateSold']) || !isset($sale['price'])) {
                $this->logger->warning('Invalid sale data structure', [
                    'market_hash_name' => $marketHashName,
                    'sale_data' => $sale,
                ]);
                continue;
            }

            // Parse date
            $dateSold = \DateTimeImmutable::createFromFormat('Y-m-d', $sale['dateSold']);
            if (!$dateSold) {
                $this->logger->warning('Invalid date format in sales data', [
                    'market_hash_name' => $marketHashName,
                    'date_sold' => $sale['dateSold'],
                ]);
                continue;
            }

            // Prepare data for bulk insert
            $salesToInsert[] = [
                'marketHashName' => $marketHashName,
                'price' => number_format((float) $sale['price'], 2, '.', ''),
                'dateSold' => $dateSold,
            ];
        }

        if (empty($salesToInsert)) {
            return 0;
        }

        // Use repository's bulk insert method (handles deduplication)
        try {
            return $this->saleHistoryRepo->bulkInsertSales($salesToInsert);
        } catch (\Exception $e) {
            $this->logger->error('Bulk insert failed', [
                'market_hash_name' => $marketHashName,
                'sales_count' => count($salesToInsert),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Delete sales older than retention period
     */
    private function cleanupOldData(): int
    {
        try {
            return $this->saleHistoryRepo->deleteOldSales(self::RETENTION_DAYS);
        } catch (\Exception $e) {
            $this->logger->error('Cleanup failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Format duration in human-readable format
     */
    private function formatDuration(float $startTime): string
    {
        $duration = microtime(true) - $startTime;
        
        if ($duration < 60) {
            return round($duration, 1) . 's';
        }
        
        $minutes = floor($duration / 60);
        $seconds = round($duration % 60);
        
        return "{$minutes}m {$seconds}s";
    }
}