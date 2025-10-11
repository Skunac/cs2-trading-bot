<?php

namespace App\Command;

use App\Service\SkinBaron\SkinBaronClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:debug-api',
    description: 'Debug raw API responses'
)]
class DebugApiCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('endpoint', InputArgument::OPTIONAL, 'Endpoint to test', '/GetExtendedPriceList');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $endpoint = $input->getArgument('endpoint');

        $io->title('Debug API Response');
        $io->info("Testing endpoint: {$endpoint}");

        try {
            $url = 'https://api.skinbaron.de' . $endpoint;
            
            $body = [
                'apikey' => $this->apiKey,
                'appId' => 730,
                'itemName' => true,
                'statTrak' => true,
                'souvenir' => true,
            ];

            $io->section('Request Details');
            $io->writeln("URL: {$url}");
            $io->writeln("Body: " . json_encode($body, JSON_PRETTY_PRINT));

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-requested-with' => 'XMLHttpRequest',
                ],
                'json' => $body
            ]);

            $statusCode = $response->getStatusCode();
            $rawContent = $response->getContent(false);

            $io->section('Response Details');
            $io->writeln("Status Code: {$statusCode}");
            $io->writeln("Content Length: " . strlen($rawContent) . " bytes");

            try {
                $json = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
                
                $io->section('JSON Structure');
                $io->writeln("Top-level keys: " . implode(', ', array_keys($json)));
                
                $io->section('Full Response (first 2000 chars)');
                $io->writeln(substr(json_encode($json, JSON_PRETTY_PRINT), 0, 2000));
                
                // Show specific structure analysis
                $io->section('Structure Analysis');
                foreach ($json as $key => $value) {
                    if (is_array($value)) {
                        $io->writeln("'{$key}': array with " . count($value) . " items");
                        if (!empty($value) && is_array($value[0] ?? null)) {
                            $io->writeln("  First item keys: " . implode(', ', array_keys($value[0])));
                        }
                    } else {
                        $io->writeln("'{$key}': " . gettype($value) . " = " . (is_scalar($value) ? $value : '(complex)'));
                    }
                }

            } catch (\JsonException $e) {
                $io->error('Failed to parse JSON: ' . $e->getMessage());
                $io->section('Raw Content (first 1000 chars)');
                $io->writeln(substr($rawContent, 0, 1000));
            }

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error('Request failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}