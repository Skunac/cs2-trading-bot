<?php

namespace App\Command;

use App\Message\BuyOpportunityMessage;
use App\Message\SellOpportunityMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:test:messenger',
    description: 'Test messenger by dispatching dummy messages'
)]
class TestMessengerCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Message type: buy or sell', 'buy');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = $input->getOption('type');

        if ($type === 'buy') {
            $this->testBuyMessage($io);
        } elseif ($type === 'sell') {
            $this->testSellMessage($io);
        } else {
            $io->error('Invalid type. Use: buy or sell');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function testBuyMessage(SymfonyStyle $io): void
    {
        $io->title('Testing Buy Message');

        // Create a dummy buy message
        $message = new BuyOpportunityMessage(
            saleId: time(),
            marketHashName: 'AK-47 | Redline (Field-Tested)',
            currentPrice: '25.00',
            targetSellPrice: '32.35',
            expectedProfit: '2.50',
            riskScore: 5.0,
            tier: 2
        );

        $io->info('Dispatching test buy message...');
        $io->table(
            ['Field', 'Value'],
            [
                ['Sale ID', $message->saleId],
                ['Item', $message->marketHashName],
                ['Price', $message->currentPrice],
                ['Target', $message->targetSellPrice],
                ['Profit', $message->expectedProfit],
                ['Risk', $message->riskScore],
                ['Tier', $message->tier],
            ]
        );

        $this->messageBus->dispatch($message);

        $io->success([
            'Buy message dispatched to queue!',
            '',
            'To process it, run:',
            '  php bin/console messenger:consume async -vv',
            '',
            'Or check queue status:',
            '  php bin/console messenger:stats',
        ]);
    }

    private function testSellMessage(SymfonyStyle $io): void
    {
        $io->title('Testing Sell Message');

        // Create a dummy sell message
        $message = new SellOpportunityMessage(
            inventoryId: 999999, // Non-existent ID
            saleId: 'TEST_' . time(),
            marketHashName: 'AK-47 | Redline (Field-Tested)',
            action: 'list',
            listPrice: '32.50',
            reason: 'Testing sell handler'
        );

        $io->info('Dispatching test sell message...');
        $io->table(
            ['Field', 'Value'],
            [
                ['Inventory ID', $message->inventoryId],
                ['Sale ID', $message->saleId],
                ['Item', $message->marketHashName],
                ['Action', $message->action],
                ['Price', $message->listPrice],
                ['Reason', $message->reason],
            ]
        );

        $this->messageBus->dispatch($message);

        $io->success([
            'Sell message dispatched to queue!',
            '',
            'To process it, run:',
            '  php bin/console messenger:consume async -vv',
            '',
            'Or check queue status:',
            '  php bin/console messenger:stats',
        ]);
    }
}