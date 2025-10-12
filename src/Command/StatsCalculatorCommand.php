<?php

namespace App\Command;

use App\Repository\SaleHistoryRepository;
use App\Repository\WhitelistedItemRepository;
use App\Service\StatsCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:stats:calculate',
    description: 'Calculate market statistics from sales history'
)]
class StatsCalculatorCommand extends Command
{
    public function __construct(
        private readonly StatsCalculator $statsCalculator,
        private readonly SaleHistoryRepository $saleHistoryRepo,
        private readonly WhitelistedItemRepository $whitelistRepo,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('item', null, InputOption::VALUE_OPTIONAL, 'Calculate stats for specific item only')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Calculate for all items with sales data (not just whitelisted)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startTime = microtime(true);

        $io->title('CS2 Trading Bot - Stats Calculator');
        $io->info('Calculating market statistics from sales history');

        // Get items to process
        $specificItem = $input->getOption('item');
        $processAll = $input->getOption('all');

        if ($specificItem) {
            $items = [$specificItem];
            $io->info("Processing single item: {$specificItem}");
        } elseif ($processAll) {
            // Get all items from sales history
            $items = $this->saleHistoryRepo->getAllItemsWithSales();
            $io->info('Processing ALL items with sales data: ' . count($items));
        } else {
            // Default: only process whitelisted items
            $whitelistedItems = $this->whitelistRepo->findBy(['isActive' => true]);
            $items = array_map(fn($item) => $item->getMarketHashName(), $whitelistedItems);
            $io->info('Processing whitelisted items only: ' . count($items));
        }

        if (empty($items)) {
            $io->warning('No items found to process. Run sales-history:fetch first or add items to whitelist.');
            return Command::FAILURE;
        }

        // Process each item
        $processed = 0;
        $withReliableData = 0;
        $withInsufficientData = 0;
        $errors = [];
        $lowVolumeItems = [];

        $progressBar = $io->createProgressBar(count($items));
        $progressBar->setFormat('verbose');
        $progressBar->start();

        foreach ($items as $marketHashName) {
            try {
                $stats = $this->statsCalculator->calculateStatsForItem($marketHashName);

                $processed++;

                // Track data quality
                if ($stats->hasReliableData()) {
                    $withReliableData++;
                } else {
                    $withInsufficientData++;
                }

                // Warn if low volume
                if ($stats->getSalesCount7d() < 5) {
                    $lowVolumeItems[] = sprintf(
                        '%s: %d sales (7d), %d sales (30d)',
                        $marketHashName,
                        $stats->getSalesCount7d(),
                        $stats->getSalesCount30d()
                    );
                }

            } catch (\Exception $e) {
                $errors[] = sprintf('%s: %s', $marketHashName, $e->getMessage());

                $this->logger->error('Failed to calculate stats', [
                    'market_hash_name' => $marketHashName,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        // Summary
        $duration = $this->formatDuration($startTime);
        
        $io->section('Summary');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Items Processed', $processed],
                ['With Reliable Data (10+ sales)', $withReliableData],
                ['With Insufficient Data', $withInsufficientData],
                ['Low Volume Items', count($lowVolumeItems)],
                ['Errors', count($errors)],
                ['Duration', $duration],
            ]
        );

        // Show low volume warnings
        if (!empty($lowVolumeItems)) {
            $io->section('Low Volume Items (Consider removing from whitelist)');
            $io->listing(array_slice($lowVolumeItems, 0, 10));
            
            if (count($lowVolumeItems) > 10) {
                $io->note('... and ' . (count($lowVolumeItems) - 10) . ' more low volume items');
            }
        }

        // Show errors
        if (!empty($errors)) {
            $io->warning('Errors encountered:');
            $io->listing(array_slice($errors, 0, 5));
            
            if (count($errors) > 5) {
                $io->note('... and ' . (count($errors) - 5) . ' more errors. Check logs for details.');
            }
        }

        // Recommendations
        if ($withInsufficientData > 0) {
            $io->note([
                "Found {$withInsufficientData} items with insufficient data (<10 sales in 30 days).",
                'These items may not be liquid enough for profitable trading.',
                'Consider removing them from your whitelist or waiting for more data.',
            ]);
        }

        if ($processed === 0) {
            return Command::FAILURE;
        }

        $io->success('Stats calculation completed!');
        
        return Command::SUCCESS;
    }

    private function formatDuration(float $startTime): string
    {
        $duration = microtime(true) - $startTime;
        
        if ($duration < 60) {
            return round($duration, 1) . 's';
        }
        
        return floor($duration / 60) . 'm ' . round($duration % 60) . 's';
    }
}