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
        private readonly LoggerInterface $logger
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
            
            // Test all log levels
            $this->logger->debug("DEBUG message (test_id: {$testId})", [
                'level' => 'debug',
                'context' => 'testing'
            ]);
            $io->info('✓ DEBUG log written');
            
            $this->logger->info("INFO message (test_id: {$testId})", [
                'level' => 'info',
                'context' => 'testing'
            ]);
            $io->info('✓ INFO log written');
            
            $this->logger->notice("NOTICE message (test_id: {$testId})", [
                'level' => 'notice',
                'context' => 'testing'
            ]);
            $io->info('✓ NOTICE log written');
            
            $this->logger->warning("WARNING message (test_id: {$testId})", [
                'level' => 'warning',
                'context' => 'testing'
            ]);
            $io->info('✓ WARNING log written');
            
            $this->logger->error("ERROR message (test_id: {$testId})", [
                'level' => 'error',
                'context' => 'testing'
            ]);
            $io->info('✓ ERROR log written');
            
            $this->logger->critical("CRITICAL message (test_id: {$testId})", [
                'level' => 'critical',
                'context' => 'testing'
            ]);
            $io->info('✓ CRITICAL log written');
            
            // Test 2: Structured logging
            $io->section('Test 2: Structured Logging');
            
            $this->logger->info('Simulating API call', [
                'endpoint' => '/GetBalance',
                'method' => 'POST',
                'duration_ms' => 245,
                'status_code' => 200,
                'test_id' => $testId
            ]);
            $io->success('✓ Structured log with context written');
            
            // Test 3: Exception logging
            $io->section('Test 3: Exception Logging');
            
            try {
                throw new \RuntimeException('Test exception for logging');
            } catch (\Throwable $e) {
                $this->logger->error('Caught test exception', [
                    'exception' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'test_id' => $testId
                ]);
                $io->success('✓ Exception logged');
            }
            
            // Summary
            $io->newLine();
            $io->success('✓ All logging tests completed!');
            
            $io->section('How to View Logs');
            $io->listing([
                'Development: var/log/dev.log',
                'Production: var/log/prod.log',
                'Or check your monolog configuration in config/packages/monolog.yaml',
            ]);
            
            $io->note("Search for test_id '{$testId}' in your logs to find these test messages");
            
            $io->newLine();
            $io->info('Run this to view recent logs:');
            $io->writeln('  tail -f var/log/dev.log');

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error('Logging test failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}