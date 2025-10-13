<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251011133152 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alerts (id SERIAL NOT NULL, alert_type VARCHAR(50) NOT NULL, severity VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, message TEXT NOT NULL, related_entity_type VARCHAR(50) DEFAULT NULL, related_entity_id INT DEFAULT NULL, context JSON DEFAULT NULL, resolved BOOLEAN NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, resolution_notes TEXT DEFAULT NULL, notification_sent BOOLEAN NOT NULL, notification_channels JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_alert_type ON alerts (alert_type)');
        $this->addSql('CREATE INDEX idx_severity ON alerts (severity)');
        $this->addSql('CREATE INDEX idx_resolved ON alerts (resolved)');
        $this->addSql('CREATE INDEX idx_created_at ON alerts (created_at)');
        $this->addSql('COMMENT ON COLUMN alerts.resolved_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN alerts.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN alerts.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE balance_snapshots (id SERIAL NOT NULL, balance NUMERIC(10, 2) NOT NULL, available_balance NUMERIC(10, 2) NOT NULL, reserved_amount NUMERIC(10, 2) NOT NULL, invested_amount NUMERIC(10, 2) NOT NULL, inventory_market_value NUMERIC(10, 2) NOT NULL, unrealized_profit NUMERIC(10, 2) NOT NULL, inventory_count INT NOT NULL, realized_profit_today NUMERIC(10, 2) NOT NULL, realized_profit_week NUMERIC(10, 2) NOT NULL, realized_profit_month NUMERIC(10, 2) NOT NULL, realized_profit_total NUMERIC(10, 2) NOT NULL, trading_state VARCHAR(20) NOT NULL, hard_floor NUMERIC(10, 2) NOT NULL, soft_floor NUMERIC(10, 2) NOT NULL, snapshot_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_snapshot_date ON balance_snapshots (snapshot_date)');
        $this->addSql('COMMENT ON COLUMN balance_snapshots.snapshot_date IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN balance_snapshots.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE inventory (id SERIAL NOT NULL, sale_id BIGINT NOT NULL, item_id BIGINT DEFAULT NULL, market_hash_name VARCHAR(255) NOT NULL, purchase_price NUMERIC(10, 2) NOT NULL, purchase_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, target_sell_price NUMERIC(10, 2) NOT NULL, status VARCHAR(20) NOT NULL, listed_price NUMERIC(10, 2) DEFAULT NULL, listed_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, sold_price NUMERIC(10, 2) DEFAULT NULL, sold_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, fee NUMERIC(10, 2) DEFAULT NULL, net_profit NUMERIC(10, 2) DEFAULT NULL, profit_pct NUMERIC(5, 2) DEFAULT NULL, risk_score NUMERIC(3, 1) NOT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B12D4A364A7E4868 ON inventory (sale_id)');
        $this->addSql('CREATE INDEX idx_inventory_market_hash_name ON inventory (market_hash_name)');
        $this->addSql('CREATE INDEX idx_inventory_status ON inventory (status)');
        $this->addSql('CREATE INDEX idx_purchase_date ON inventory (purchase_date)');
        $this->addSql('CREATE INDEX idx_sold_date ON inventory (sold_date)');
        $this->addSql('COMMENT ON COLUMN inventory.purchase_date IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN inventory.listed_date IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN inventory.sold_date IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN inventory.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN inventory.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE price_history (id SERIAL NOT NULL, market_hash_name VARCHAR(255) NOT NULL, price NUMERIC(10, 2) NOT NULL, listings_count INT DEFAULT NULL, timestamp TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_market_timestamp ON price_history (market_hash_name, timestamp)');
        $this->addSql('CREATE INDEX idx_timestamp ON price_history (timestamp)');
        $this->addSql('COMMENT ON COLUMN price_history.timestamp IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE sales_stats (id SERIAL NOT NULL, market_hash_name VARCHAR(255) NOT NULL, avg_price7d NUMERIC(10, 2) DEFAULT NULL, avg_price30d NUMERIC(10, 2) DEFAULT NULL, median_price30d NUMERIC(10, 2) DEFAULT NULL, min_price30d NUMERIC(10, 2) DEFAULT NULL, max_price30d NUMERIC(10, 2) DEFAULT NULL, price_volatility NUMERIC(10, 2) DEFAULT NULL, data_points INT NOT NULL, current_price NUMERIC(10, 2) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_updated_at ON sales_stats (updated_at)');
        $this->addSql('CREATE UNIQUE INDEX unique_market_hash_name ON sales_stats (market_hash_name)');
        $this->addSql('COMMENT ON COLUMN sales_stats.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN sales_stats.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE system_config (id SERIAL NOT NULL, config_key VARCHAR(100) NOT NULL, config_value TEXT NOT NULL, type VARCHAR(20) NOT NULL, description TEXT DEFAULT NULL, is_editable BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX unique_config_key ON system_config (config_key)');
        $this->addSql('COMMENT ON COLUMN system_config.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN system_config.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE transactions (id SERIAL NOT NULL, inventory_id INT DEFAULT NULL, transaction_type VARCHAR(10) NOT NULL, market_hash_name VARCHAR(255) NOT NULL, external_id BIGINT NOT NULL, price NUMERIC(10, 2) NOT NULL, fee NUMERIC(10, 2) NOT NULL, net_amount NUMERIC(10, 2) NOT NULL, balance_before NUMERIC(10, 2) NOT NULL, balance_after NUMERIC(10, 2) NOT NULL, status VARCHAR(20) NOT NULL, transaction_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, error_message TEXT DEFAULT NULL, metadata JSON DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_EAA81A4C9EEA759 ON transactions (inventory_id)');
        $this->addSql('CREATE INDEX idx_transaction_type ON transactions (transaction_type)');
        $this->addSql('CREATE INDEX idx_transactions_market_hash_name ON transactions (market_hash_name)');
        $this->addSql('CREATE INDEX idx_transaction_date ON transactions (transaction_date)');
        $this->addSql('CREATE INDEX idx_transactions_status ON transactions (status)');
        $this->addSql('COMMENT ON COLUMN transactions.transaction_date IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN transactions.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN transactions.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE whitelisted_items (id SERIAL NOT NULL, market_hash_name VARCHAR(255) NOT NULL, tier SMALLINT NOT NULL, min_discount_pct NUMERIC(5, 2) NOT NULL, min_spread_pct NUMERIC(5, 2) NOT NULL, target_profit_pct NUMERIC(5, 2) NOT NULL, max_holdings SMALLINT NOT NULL, is_active BOOLEAN NOT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_982E11FF74A2D221 ON whitelisted_items (market_hash_name)');
        $this->addSql('CREATE INDEX idx_whitelisted_items_market_hash_name ON whitelisted_items (market_hash_name)');
        $this->addSql('CREATE INDEX idx_is_active ON whitelisted_items (is_active)');
        $this->addSql('CREATE INDEX idx_tier ON whitelisted_items (tier)');
        $this->addSql('COMMENT ON COLUMN whitelisted_items.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN whitelisted_items.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4C9EEA759 FOREIGN KEY (inventory_id) REFERENCES inventory (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transactions DROP CONSTRAINT FK_EAA81A4C9EEA759');
        $this->addSql('DROP TABLE alerts');
        $this->addSql('DROP TABLE balance_snapshots');
        $this->addSql('DROP TABLE inventory');
        $this->addSql('DROP TABLE price_history');
        $this->addSql('DROP TABLE sales_stats');
        $this->addSql('DROP TABLE system_config');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('DROP TABLE whitelisted_items');
    }
}
