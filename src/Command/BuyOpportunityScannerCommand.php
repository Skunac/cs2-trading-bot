<?php

namespace App\Command;

use App\Message\BuyOpportunityMessage;
use App\Repository\WhitelistedItemRepository;
use App\Service\BuyDecisionEngine;
use App\Service\DTO\BuyOpportunity;
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
    name: 'app:buy-opportunity:scan',
    description: 'Scan market for buy opportunities'
)]
class BuyOpportunityScannerCommand extends Command
{
    private const RESULTS_PER_ITEM = 50;
    private const DELAY_BETWEEN_SEARCHES_MS = 100000; // 100ms

    public function __construct(
        private readonly SkinBaronClient $skinBaronClient,
        private readonly WhitelistedItemRepository $whitelistRepo,
        private readonly BuyDecisionEngine $buyDecisionEngine,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run - evaluate but do not dispatch messages')
            ->addOption('item', null, InputOption::VALUE_OPTIONAL, 'Scan specific item only')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit results per item', self::RESULTS_PER_ITEM)
            ->addOption('tier', null, InputOption::VALUE_OPTIONAL, 'Scan only specific tier (1 or 2)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $startTime = microtime(true);
        $isDryRun = $input->getOption('dry-run');

        $io->title('CS2 Trading Bot - Buy Opportunity Scanner');
        
        if ($isDryRun) {
            $io->warning('DRY-RUN MODE: No messages will be dispatched');
        }

        // Load whitelisted items
        $whitelistedItems = $this->loadWhitelistedItems($input, $io);
        
        if (empty($whitelistedItems)) {
            $io->warning('No active whitelisted items found');
            return Command::FAILURE;
        }

        $io->info('Scanning ' . count($whitelistedItems) . ' whitelisted items');
        $io->info('Results per item: ' . $input->getOption('limit'));

        // Scan each item
        $totalSearched = 0;
        $totalListingsFound = 0;
        $totalOpportunities = 0;
        $totalMessagesDispatched = 0;
        $opportunitiesByItem = [];
        $opportunitiesByTier = [1 => 0, 2 => 0];
        $errors = [];

        $progressBar = $io->createProgressBar(count($whitelistedItems));
        $progressBar->setFormat('verbose');
        $progressBar->start();

        foreach ($whitelistedItems as $item) {
            try {
                $marketHashName = $item->getMarketHashName();
                $tier = $item->getTier();
                
                $this->logger->debug('Scanning item', [
                    'market_hash_name' => $marketHashName,
                    'tier' => $tier,
                    'min_discount_pct' => $item->getMinDiscountPct(),
                    'min_spread_pct' => $item->getMinSpreadPct(),
                ]);

                // Search for listings
                $listings = $this->skinBaronClient->search(
                    marketHashName: $marketHashName,
                    limit: (int) $input->getOption('limit')
                );

                $totalSearched++;
                $totalListingsFound += count($listings);
                $itemOpportunities = 0;

                // Evaluate each listing
                foreach ($listings as $listing) {
                    $opportunity = $this->buyDecisionEngine->evaluateListing($listing);

                    if ($opportunity) {
                        $totalOpportunities++;
                        $itemOpportunities++;
                        $opportunitiesByTier[$tier]++;

                        if ($isDryRun) {
                            // In dry-run, just log
                            $this->logger->info('[DRY-RUN] Buy opportunity found', $opportunity->toArray());
                        } else {
                            // Dispatch message to queue
                            $message = new BuyOpportunity(
                                saleId: $opportunity->saleId,
                                marketHashName: $opportunity->marketHashName,
                                currentPrice: $opportunity->currentPrice,
                                targetSellPrice: $opportunity->targetSellPrice,
                                expectedProfit: $opportunity->expectedProfit,
                                riskScore: $opportunity->riskScore,
                                tier: $opportunity->tier
                            );

                            $this->messageBus->dispatch($message);
                            $totalMessagesDispatched++;

                            $this->logger->info('[PRODUCTION] Buy opportunity dispatched to queue', $opportunity->toArray());
                        }
                    }
                }

                if ($itemOpportunities > 0) {
                    $opportunitiesByItem[$marketHashName] = [
                        'count' => $itemOpportunities,
                        'tier' => $tier,
                    ];
                }

                $this->logger->debug('Item scan complete', [
                    'market_hash_name' => $marketHashName,
                    'listings_found' => count($listings),
                    'opportunities' => $itemOpportunities,
                ]);

            } catch (\Exception $e) {
                $errorMsg = sprintf('%s: %s', $item->getMarketHashName(), $e->getMessage());
                $errors[] = $errorMsg;
                
                $this->logger->error('Failed to scan item', [
                    'market_hash_name' => $item->getMarketHashName(),
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);
            }

            $progressBar->advance();

            // Delay to respect rate limits
            usleep(self::DELAY_BETWEEN_SEARCHES_MS);
        }

        $progressBar->finish();
        $io->newLine(2);

        // Summary
        $this->displaySummary($io, [
            'total_searched' => $totalSearched,
            'total_listings' => $totalListingsFound,
            'total_opportunities' => $totalOpportunities,
            'total_dispatched' => $totalMessagesDispatched,
            'opportunities_by_item' => $opportunitiesByItem,
            'opportunities_by_tier' => $opportunitiesByTier,
            'errors' => $errors,
            'duration' => $this->formatDuration($startTime),
            'is_dry_run' => $isDryRun,
        ]);

        if ($isDryRun) {
            $io->success('Dry-run completed! Review logs to see decision details.');
            $io->note('Remove --dry-run flag to enable message dispatching in production.');
        } else {
            $io->success('Scan completed! Messages dispatched to queue.');
            
            if ($totalMessagesDispatched > 0) {
                $io->info([
                    "Workers will process these opportunities in the background.",
                    "Monitor queue: php bin/console messenger:stats",
                    "View worker logs: tail -f var/log/trading.log"
                ]);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Load whitelisted items based on command options
     */
    private function loadWhitelistedItems(InputInterface $input, SymfonyStyle $io): array
    {
        $specificItem = $input->getOption('item');
        $tierFilter = $input->getOption('tier');

        if ($specificItem) {
            $item = $this->whitelistRepo->findByMarketHashName($specificItem);
            
            if (!$item || !$item->isActive()) {
                $io->error("Item '{$specificItem}' not found in whitelist or not active");
                return [];
            }
            
            $io->info("Scanning single item: {$specificItem}");
            return [$item];
        }

        if ($tierFilter) {
            $items = $this->whitelistRepo->findActiveByTier((int) $tierFilter);
            $io->info("Filtering by tier: {$tierFilter}");
            return $items;
        }

        return $this->whitelistRepo->findActive();
    }

    /**
     * Display comprehensive summary of scan results
     */
    private function displaySummary(SymfonyStyle $io, array $data): void
    {
        $duration = $data['duration'];
        $isDryRun = $data['is_dry_run'];
        
        $io->section('Summary');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Items Scanned', $data['total_searched']],
                ['Listings Analyzed', $data['total_listings']],
                ['Opportunities Found', $data['total_opportunities']],
                ['  - Tier 1 Opportunities', $data['opportunities_by_tier'][1]],
                ['  - Tier 2 Opportunities', $data['opportunities_by_tier'][2]],
                ['Items With Opportunities', count($data['opportunities_by_item'])],
                ['Messages Dispatched', $isDryRun ? 0 : $data['total_dispatched']],
                ['Errors', count($data['errors'])],
                ['Duration', $duration],
                ['Mode', $isDryRun ? 'DRY-RUN' : 'PRODUCTION'],
            ]
        );

        // Show opportunities by item
        if (!empty($data['opportunities_by_item'])) {
            $io->section('Top Opportunities by Item');
            $tableData = [];
            
            foreach ($data['opportunities_by_item'] as $itemName => $info) {
                $tableData[] = [
                    $itemName,
                    $info['count'],
                    'Tier ' . $info['tier'],
                ];
            }
            
            // Sort by count descending
            usort($tableData, fn($a, $b) => $b[1] <=> $a[1]);
            
            $io->table(
                ['Item', 'Opportunities', 'Tier'],
                array_slice($tableData, 0, 15) // Show top 15
            );
            
            if (count($tableData) > 15) {
                $io->note('Showing top 15 of ' . count($tableData) . ' items with opportunities');
            }
        } else {
            $io->note('No opportunities found. Market conditions may not be favorable right now.');
            $io->info([
                'Possible reasons:',
                '  • Market prices are at or above averages',
                '  • Not enough discount from 7-day averages',
                '  • Items not meeting historical viability checks',
                '  • Risk scores too high',
                '  • Budget constraints active',
            ]);
        }

        // Show errors
        if (!empty($data['errors'])) {
            $io->section('Errors Encountered');
            $io->listing(array_slice($data['errors'], 0, 10));
            
            if (count($data['errors']) > 10) {
                $io->note('... and ' . (count($data['errors']) - 10) . ' more errors. Check logs for details.');
            }
            
            $io->warning('Some items failed to scan. Check API connectivity and rate limits.');
        }

        // Performance metrics
        if ($data['total_searched'] > 0) {
            $avgListingsPerItem = round($data['total_listings'] / $data['total_searched'], 1);
            $opportunityRate = round(($data['total_opportunities'] / max($data['total_listings'], 1)) * 100, 2);
            
            $io->section('Performance Metrics');
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Avg Listings per Item', $avgListingsPerItem],
                    ['Opportunity Rate', $opportunityRate . '%'],
                    ['Scan Speed', round($data['total_searched'] / max($this->parseDuration($duration), 1), 1) . ' items/sec'],
                ]
            );
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

    /**
     * Parse duration string back to seconds (for calculations)
     */
    private function parseDuration(string $duration): float
    {
        if (str_contains($duration, 'm')) {
            preg_match('/(\d+)m\s*(\d+)s/', $duration, $matches);
            return (float) ($matches[1] * 60 + $matches[2]);
        }
        
        return (float) str_replace('s', '', $duration);
    }
}