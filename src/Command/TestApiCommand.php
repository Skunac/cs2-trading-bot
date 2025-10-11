<?php

namespace App\Command;

use App\Service\SkinBaron\SkinBaronClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-api',
    description: 'Test SkinBaron API connection'
)]
class TestApiCommand extends Command
{
    public function __construct(
        private readonly SkinBaronClient $skinBaronClient
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $io->title('SkinBaron API Connection Test');

            // Test 1: Balance
            $io->section('Test 1: Get Balance');
            $balance = $this->skinBaronClient->getBalance();
            $io->success("Balance: €{$balance}");

            // Test 2: Extended Price List
            $io->section('Test 2: Get Extended Price List');
            $io->info('Fetching price list (this may take a moment)...');
            
            $priceList = $this->skinBaronClient->getExtendedPriceList();
            $io->success('Price list returned ' . count($priceList) . ' items');
            
            if (!empty($priceList)) {
                $io->info('Sample of first 5 items:');
                $priceTableData = [];
                foreach (array_slice($priceList, 0, 5) as $item) {
                    $priceTableData[] = [
                        $item['name'] ?? $item['marketHashName'] ?? 'Unknown',
                        '€' . number_format($item['lowestPrice'] ?? 0, 2),
                        ($item['statTrak'] ?? false) ? 'Yes' : 'No',
                        $item['exterior'] ?? 'N/A'
                    ];
                }
                
                $io->table(
                    ['Item Name', 'Lowest Price', 'StatTrak', 'Exterior'],
                    $priceTableData
                );
            }

            // Test 3: Search - try a simpler search term
            $io->section('Test 3: Search for Items');
            $io->info('Searching for "AK-47"...');
            
            $results = $this->skinBaronClient->search('AK-47', 10);
            $io->success('Search returned ' . count($results) . ' results');

            if (!empty($results)) {
                $searchTableData = [];
                foreach (array_slice($results, 0, 5) as $item) {
                    $searchTableData[] = [
                        $item['market_name'] ?? 'Unknown',
                        '€' . number_format($item['price'] ?? 0, 2),
                        $item['id'] ?? 'N/A',
                    ];
                }
                
                $io->table(
                    ['Name', 'Price', 'id'],
                    $searchTableData
                );
            } else {
                $io->warning('No results - this is expected if there are no AK-47s listed');
            }

            // Test 4: Search for a common cheap item
            $io->section('Test 4: Search for Common Item');
            $io->info('Searching for "Case"...');
            
            $caseResults = $this->skinBaronClient->search('Case', 5);
            $io->success('Case search returned ' . count($caseResults) . ' results');
            
            if (!empty($caseResults)) {
                $io->info('Sample results:');
                foreach (array_slice($caseResults, 0, 3) as $item) {
                    $io->writeln(sprintf(
                        '  • %s - €%s (ID: %s)',
                        $item['market_name'] ?? 'Unknown',
                        number_format($item['price'] ?? 0, 2),
                        $item['id'] ?? 'N/A'
                    ));
                }
            }

            // Test 5: Inventory
            $io->section('Test 5: Get Inventory');
            $inventory = $this->skinBaronClient->getInventory();
            $io->info('Inventory items: ' . count($inventory));
            
            if (!empty($inventory)) {
                $io->listing(array_slice(array_map(
                    fn($item) => ($item['itemName'] ?? $item['localizedName'] ?? $item['name'] ?? 'Unknown') . 
                                 ' - €' . number_format($item['suggestedPrice'] ?? $item['price'] ?? 0, 2),
                    $inventory
                ), 0, 5));
            } else {
                $io->note('No items in inventory (this is normal if you haven\'t purchased anything yet)');
            }

            // Summary
            $io->newLine();
            $io->success('✓ API client is working correctly!');
            
            $io->section('Summary');
            $io->table(
                ['Test', 'Status', 'Details'],
                [
                    ['Get Balance', '✓ Passed', "€{$balance}"],
                    ['Extended Price List', '✓ Passed', count($priceList) . ' items'],
                    ['Search (AK-47)', count($results) > 0 ? '✓ Passed' : '⚠ No results', count($results) . ' results'],
                    ['Search (Case)', count($caseResults) > 0 ? '✓ Passed' : '⚠ No results', count($caseResults) . ' results'],
                    ['Get Inventory', '✓ Passed', count($inventory) . ' items'],
                ]
            );

            $io->newLine();
            $io->info('The API client is ready. You can now build the price scraper and trading logic.');

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error('API test failed: ' . $e->getMessage());
            $io->note('Exception class: ' . get_class($e));
            
            if (method_exists($e, 'getResponseData') && $e->getResponseData()) {
                $io->section('Response Data:');
                $io->writeln(json_encode($e->getResponseData(), JSON_PRETTY_PRINT));
            }
            
            if ($e->getPrevious()) {
                $io->section('Previous Exception:');
                $io->writeln($e->getPrevious()->getMessage());
            }
            
            return Command::FAILURE;
        }
    }
}