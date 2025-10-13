<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251013204721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insert default system configuration values';
    }

    public function up(Schema $schema): void
    {
        $configs = [
            // Budget constraints
            ['budget.hard_floor', '10.00', 'float', 'Absolute minimum balance (lockdown threshold)', true],
            ['budget.soft_floor', '12.00', 'float', 'Warning threshold (emergency mode)', true],
            ['budget.max_risk_per_trade', '0.05', 'float', 'Maximum % of balance per trade (5%)', true],
            ['budget.max_total_exposure', '0.70', 'float', 'Maximum % of balance invested (70%)', true],
            ['budget.min_reserve_pct', '0.20', 'float', 'Minimum % of balance to keep liquid (20%)', true],
            
            // Trading settings
            ['trading.enabled', '1', 'boolean', 'Master switch for all trading', true],
            ['trading.max_items_per_skin', '3', 'integer', 'Maximum holdings of same item', true],
        ];

        foreach ($configs as [$key, $value, $type, $description, $isEditable]) {
            $this->addSql(
                "INSERT INTO system_config (config_key, config_value, type, description, is_editable, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ON CONFLICT (config_key) DO NOTHING",
                [$key, $value, $type, $description, $isEditable]
            );
        }

    }

    public function down(Schema $schema): void
    {
        $keys = [
            'budget.hard_floor',
            'budget.soft_floor',
            'budget.max_risk_per_trade',
            'budget.max_total_exposure',
            'budget.min_reserve_pct',
            'trading.enabled',
            'trading.max_items_per_skin',
        ];

        foreach ($keys as $key) {
            $this->addSql('DELETE FROM system_config WHERE config_key = ?', [$key]);
        }
    }
}
