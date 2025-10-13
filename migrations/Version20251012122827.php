<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251012122827 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add 2 sample items to whitelisted_items table';
    }

    public function up(Schema $schema): void
    {
        // Insert 2 popular, liquid items to start with
        $this->addSql("
            INSERT INTO whitelisted_items (
                market_hash_name,
                tier,
                min_discount_pct,
                min_spread_pct,
                target_profit_pct,
                max_holdings,
                is_active,
                notes,
                created_at,
                updated_at
            ) VALUES 
            (
                'AK-47 | Redline (Field-Tested)',
                1,
                20.00,
                15.00,
                10.00,
                3,
                true,
                'Popular Tier 1 skin - high liquidity, stable prices',
                NOW(),
                NOW()
            ),
            (
                'AWP | Asiimov (Field-Tested)',
                2,
                25.00,
                15.00,
                10.00,
                2,
                true,
                'Tier 2 skin - higher value, medium liquidity',
                NOW(),
                NOW()
            )
        ");
    }

    public function down(Schema $schema): void
    {
        // Remove the test items
        $this->addSql("DELETE FROM whitelisted_items WHERE market_hash_name IN ('AK-47 | Redline (Field-Tested)', 'AWP | Asiimov (Field-Tested)')");
    }
}