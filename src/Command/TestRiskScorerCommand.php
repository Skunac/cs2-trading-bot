<?php

namespace App\Command;

use App\Service\RiskScorer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-risk-scorer',
    description: 'Test RiskScorer service with sample items'
)]
class TestRiskScorerCommand extends Command
{
    public function __construct(
        private readonly RiskScorer $riskScorer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('item', InputArgument::OPTIONAL, 'Item name to test');
        $this->addArgument('price', InputArgument::OPTIONAL, 'Current price', '10.00');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('RiskScorer Test');

        $itemName = $input->getArgument('item');
        $currentPrice = $input->getArgument('price');

        if ($itemName) {
            // Test specific item
            return $this->testItem($io, $itemName, $currentPrice);
        }

        // Test multiple scenarios
        return $this->testMultipleScenarios($io);
    }

    private function testItem(SymfonyStyle $io, string $itemName, string $currentPrice): int
    {
        $io->section("Testing: {$itemName} @ €{$currentPrice}");

        try {
            // Get detailed assessment
            $assessment = $this->riskScorer->getDetailedRiskAssessment($itemName, $currentPrice);

            // Display score
            $scoreColor = match($assessment['level']) {
                'MINIMAL', 'LOW' => 'green',
                'MEDIUM' => 'yellow',
                'HIGH', 'CRITICAL' => 'red',
            };

            $io->writeln(sprintf(
                "Risk Score: <fg=%s;options=bold>%.1f / 10.0 (%s)</>",
                $scoreColor,
                $assessment['score'],
                $assessment['level']
            ));

            $io->writeln(sprintf(
                "Acceptable: %s",
                $assessment['acceptable'] ? '<fg=green>✓ Yes</>' : '<fg=red>✗ No</>'
            ));

            // Display factors
            if (!empty($assessment['factors'])) {
                $io->newLine();
                $io->section('Risk Factors');

                $factorTable = [];
                
                // Volatility
                if (isset($assessment['factors']['volatility'])) {
                    $vol = $assessment['factors']['volatility'];
                    $factorTable[] = [
                        'Volatility',
                        $vol['value'] ?? 'N/A',
                        "Threshold: {$vol['threshold']}",
                        $vol['risky'] ? '<fg=red>⚠ High</>' : '<fg=green>✓ OK</>',
                    ];
                }

                // Liquidity
                if (isset($assessment['factors']['liquidity'])) {
                    $liq = $assessment['factors']['liquidity'];
                    $factorTable[] = [
                        'Avg Sales/Day',
                        $liq['value'] ?? 'N/A',
                        "Threshold: {$liq['threshold']}",
                        $liq['risky'] ? '<fg=red>⚠ Low</>' : '<fg=green>✓ OK</>',
                    ];
                }

                // Holdings
                if (isset($assessment['factors']['current_holdings'])) {
                    $hold = $assessment['factors']['current_holdings'];
                    $factorTable[] = [
                        'Current Holdings',
                        $hold['value'],
                        "Max: {$hold['max']}",
                        $hold['value'] > 0 ? '<fg=yellow>⚠ Concentrated</>' : '<fg=green>✓ Diversified</>',
                    ];
                }

                // Data quality
                if (isset($assessment['factors']['data_points'])) {
                    $data = $assessment['factors']['data_points'];
                    $factorTable[] = [
                        'Data Points (30d)',
                        $data['value'],
                        "Min: {$data['min_reliable']}",
                        $data['reliable'] ? '<fg=green>✓ Reliable</>' : '<fg=red>⚠ Insufficient</>',
                    ];
                }

                $io->table(['Factor', 'Value', 'Threshold', 'Status'], $factorTable);
            }

            $io->success('✓ Risk assessment completed');
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error('Test failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function testMultipleScenarios(SymfonyStyle $io): int
    {
        $io->section('Testing Multiple Scenarios');

        $scenarios = [
            ['AK-47 | Redline (Field-Tested)', '28.00', 'Should be low risk (popular item)'],
            ['AWP | Asiimov (Field-Tested)', '45.00', 'Should be low risk (high liquidity)'],
            ['Some Rare Knife', '500.00', 'Should be high risk (no data)'],
        ];

        $results = [];

        foreach ($scenarios as [$item, $price, $description]) {
            try {
                $score = $this->riskScorer->calculateRiskScore($item, $price);
                $level = $this->riskScorer->getRiskLevel($score);
                $acceptable = $this->riskScorer->isAcceptableRisk($score);

                $results[] = [
                    $item,
                    '€' . $price,
                    sprintf('%.1f', $score),
                    $level,
                    $acceptable ? '✓' : '✗',
                    $description,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    $item,
                    '€' . $price,
                    'ERROR',
                    $e->getMessage(),
                    '✗',
                    $description,
                ];
            }
        }

        $io->table(
            ['Item', 'Price', 'Score', 'Level', 'OK?', 'Note'],
            $results
        );

        $io->success('✓ Multiple scenario test completed');
        return Command::SUCCESS;
    }
}