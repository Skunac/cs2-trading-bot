<?php

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-logging',
    description: 'Test logging configuration'
)]
class TestLoggingCommand extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'monolog.logger.trading')] private readonly LoggerInterface $tradingLogger,
        #[Autowire(service: 'monolog.logger.api')] private readonly LoggerInterface $apiLogger,
        #[Autowire(service: 'monolog.logger.budget')] private readonly LoggerInterface $budgetLogger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Logging Test');

        try {
            $io->section('Test 1: Log Levels');
            
            $testId = uniqid('test_');
            
            // Default app channel
            $this->logger->info("Default [app] channel log (test_id: {$testId})");
            $io->info('✓ Default [app] channel log written');

            // Trading channel
            $this->tradingLogger->info("Trading channel log (test_id: {$testId})", [
                'operation' => 'buy',
                'item' => 'AWP | Dragon Lore',
                'price' => 10000,
            ]);
            $io->info('✓ Trading channel log written');

            // API channel
            $this->apiLogger->info("API channel log (test_id: {$testId})", [
                'endpoint' => '/GetBalance',
                'method' => 'POST',
                'status_code' => 200,
            ]);
            $io->info('✓ API channel log written');

            // Budget channel
            $this->budgetLogger->warning("Budget channel log (test_id: {$testId})", [
                'balance' => 50.0,
                'threshold' => 100.0,
            ]);
            $io->info('✓ Budget channel log written');

            // Test 2: Structured logging
            $io->section('Test 2: Structured Logging');
            $this->apiLogger->info('Structured API log', [
                'endpoint' => '/GetInventory',
                'method' => 'POST',
                'duration_ms' => 123,
                'status_code' => 200,
                'test_id' => $testId
            ]);
            $io->success('✓ Structured log with context written to API channel');

            // Test 3: Exception logging
            $io->section('Test 3: Exception Logging');
            try {
                throw new \RuntimeException('Test exception for logging');
            } catch (\Throwable $e) {
                $this->tradingLogger->error('Caught test exception in trading channel', [
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'test_id' => $testId
                ]);
                $io->success('✓ Exception logged to trading channel');
            }

            // Summary
            $io->newLine();
            $io->success('✓ All logging tests completed!');

            $io->section('How to View Logs');
            $io->listing([
                'Default: var/log/dev.log',
                'Trading: var/log/trading.log',
                'API: var/log/api.log',
                'Budget: var/log/budget.log',
                'Or check your monolog configuration in config/packages/monolog.yaml',
            ]);

            $io->note("Search for test_id '{$testId}' in your logs to find these test messages");

            $io->newLine();
            $io->info('Run this to view recent logs:');
            $io->writeln('  tail -f var/log/dev.log var/log/trading.log var/log/api.log var/log/budget.log');

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error('Logging test failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}