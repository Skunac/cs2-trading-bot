<?php

namespace App\Command;

use App\Message\SellOpportunityMessage;
use App\Repository\InventoryRepository;
use App\Service\DTO\SellOpportunity;
use App\Service\SellDecisionEngine;
use App\Service\SkinBaron\SkinBaronClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:sell-opportunity:scan',
    description: 'Scan inventory for sell opportunities'
)]
class SellOpportunityScannerCommand extends Command
{
    private const DELAY_BETWEEN_API_CALLS_MS = 100000; // 100ms

    public function __construct(
        private readonly InventoryRepository $inventoryRepo,
        private readonly SellDecisionEngine $sellDecisionEngine,
        private readonly SkinBaronClient $skinBaronClient,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run - evaluate but do not dispatch messages')
            ->addOption('status', null, InputOption::VALUE_OPTIONAL, 'Filter by status (holding, listed)', null)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startTime = microtime(true);
        $isDryRun = $input->getOption('dry-run');

        $io->title('CS2 Trading Bot - Sell Opportunity Scanner');
        
        if ($isDryRun) {
            $io->warning('DRY-RUN MODE: No messages will be dispatched');
        }

        // Clear cache from any previous run
        $this->sellDecisionEngine->clearCache();

        // Load inventory
        $statusFilter = $input->getOption('status');
        if ($statusFilter) {
            $inventory = $this->inventoryRepo->findBy(
                ['status' => $statusFilter],
                ['purchaseDate' => 'ASC']
            );
            $io->info("Filtering by status: {$statusFilter}");
        } else {
            $inventory = $this->inventoryRepo->findHoldings();
        }

        if (empty($inventory)) {
            $io->note('No inventory items to evaluate');
            return Command::SUCCESS;
        }

        $io->info('Evaluating ' . count($inventory) . ' inventory items');

        // Step 1: Group inventory by marketHashName to batch API calls
        $itemsByMarketName = [];
        foreach ($inventory as $item) {
            $marketHashName = $item->getMarketHashName();
            if (!isset($itemsByMarketName[$marketHashName])) {
                $itemsByMarketName[$marketHashName] = [];
            }
            $itemsByMarketName[$marketHashName][] = $item;
        }

        $io->info('Found ' . count($itemsByMarketName) . ' unique items (will make ' . count($itemsByMarketName) . ' API calls)');

        // Step 2: Fetch all market listings (one call per unique item)
        $marketListingsCache = [];
        $apiCallsCount = 0;

        $io->section('Fetching Market Listings');
        $fetchProgressBar = $io->createProgressBar(count($itemsByMarketName));
        $fetchProgressBar->start();

        foreach (array_keys($itemsByMarketName) as $marketHashName) {
            try {
                $listings = $this->skinBaronClient->search(
                    marketHashName: $marketHashName,
                    limit: 20
                );

                $marketListingsCache[$marketHashName] = $listings;
                $apiCallsCount++;

                $this->logger->debug('Fetched market listings', [
                    'market_hash_name' => $marketHashName,
                    'listings_count' => count($listings),
                ]);

                // Rate limit protection
                usleep(self::DELAY_BETWEEN_API_CALLS_MS);

            } catch (\Exception $e) {
                $marketListingsCache[$marketHashName] = [];
                
                $this->logger->error('Failed to fetch listings', [
                    'market_hash_name' => $marketHashName,
                    'error' => $e->getMessage(),
                ]);
            }

            $fetchProgressBar->advance();
        }

        $fetchProgressBar->finish();
        $io->newLine(2);

        // Step 3: Evaluate each inventory item using cached listings
        $io->section('Evaluating Inventory Items');
        
        $totalEvaluated = 0;
        $totalOpportunities = 0;
        $actionCounts = [
            'list' => 0,
            'adjust' => 0,
        ];
        $reasonCounts = [];

        $evalProgressBar = $io->createProgressBar(count($inventory));
        $evalProgressBar->start();

        foreach ($inventory as $item) {
            try {
                $totalEvaluated++;
                $marketHashName = $item->getMarketHashName();

                // Pass the pre-fetched listings to avoid API call
                $listings = $marketListingsCache[$marketHashName] ?? [];
                $opportunity = $this->sellDecisionEngine->evaluateItem($item, $listings);

                if ($opportunity) {
                    $totalOpportunities++;
                    $actionCounts[$opportunity->action]++;
                    
                    $reason = $opportunity->reason;
                    $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;

                    if ($isDryRun) {
                        $this->logger->info('[DRY-RUN] Sell opportunity found', $opportunity->toArray());
                    } else {
                        $message = new SellOpportunityMessage(
                            inventoryId: $opportunity->inventoryId,
                            saleId: $opportunity->saleId,
                            marketHashName: $opportunity->marketHashName,
                            action: $opportunity->action,
                            listPrice: $opportunity->listPrice,
                            reason: $opportunity->reason
                        );
                        
                        $this->messageBus->dispatch($message);
                        
                        $this->logger->info('[PRODUCTION] Sell opportunity dispatched', $opportunity->toArray());
                    }
                }

            } catch (\Exception $e) {
                $this->logger->error('Failed to evaluate item', [
                    'inventory_id' => $item->getId(),
                    'market_hash_name' => $item->getMarketHashName(),
                    'error' => $e->getMessage(),
                ]);
            }

            $evalProgressBar->advance();
        }

        $evalProgressBar->finish();
        $io->newLine(2);

        // Summary
        $duration = $this->formatDuration($startTime);
        
        $io->section('Summary');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Unique Items', count($itemsByMarketName)],
                ['API Calls Made', $apiCallsCount],
                ['Items Evaluated', $totalEvaluated],
                ['Opportunities Found', $totalOpportunities],
                ['  - List Actions', $actionCounts['list']],
                ['  - Adjust Actions', $actionCounts['adjust']],
                ['Items to Hold', $totalEvaluated - $totalOpportunities],
                ['Duration', $duration],
                ['Mode', $isDryRun ? 'DRY-RUN' : 'PRODUCTION'],
            ]
        );

        // Show breakdown by reason
        if (!empty($reasonCounts)) {
            $io->section('Actions by Reason');
            $tableData = [];
            foreach ($reasonCounts as $reason => $count) {
                $tableData[] = [$reason, $count];
            }
            arsort($tableData);
            $io->table(['Reason', 'Count'], $tableData);
        } else {
            $io->note('No sell opportunities found. All items are being held.');
        }

        $io->success([
            "Optimized scan completed!",
            "Made only {$apiCallsCount} API calls for {$totalEvaluated} items",
            "That's " . round(($apiCallsCount / max($totalEvaluated, 1)) * 100, 1) . "% of what naive approach would use"
        ]);

        if ($isDryRun) {
            $io->note('Remove --dry-run flag to enable message dispatching in production.');
        }

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