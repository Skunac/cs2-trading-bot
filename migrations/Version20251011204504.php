<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251011204504 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP SEQUENCE price_history_id_seq CASCADE');
        $this->addSql('CREATE TABLE sale_history (id SERIAL NOT NULL, market_hash_name VARCHAR(255) NOT NULL, price NUMERIC(10, 2) NOT NULL, date_sold DATE NOT NULL, fetched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_sale_market_date ON sale_history (market_hash_name, date_sold)');
        $this->addSql('CREATE INDEX idx_date_sold ON sale_history (date_sold)');
        $this->addSql('CREATE INDEX idx_fetched_at ON sale_history (fetched_at)');
        $this->addSql('CREATE UNIQUE INDEX unique_sale ON sale_history (market_hash_name, date_sold, price)');
        $this->addSql('COMMENT ON COLUMN sale_history.date_sold IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN sale_history.fetched_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('DROP TABLE price_history');
        $this->addSql('ALTER TABLE sales_stats ADD sales_count7d INT NOT NULL');
        $this->addSql('ALTER TABLE sales_stats ADD sales_count30d INT NOT NULL');
        $this->addSql('ALTER TABLE sales_stats ADD avg_sales_per_day NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE sales_stats ADD last_sale_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE sales_stats RENAME COLUMN current_price TO last_sale_price');
        $this->addSql('COMMENT ON COLUMN sales_stats.last_sale_date IS \'(DC2Type:date_immutable)\'');
        $this->addSql('ALTER INDEX idx_whitelisted_items_market_hash_name RENAME TO idx_market_hash_name');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE SEQUENCE price_history_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE price_history (id SERIAL NOT NULL, market_hash_name VARCHAR(255) NOT NULL, price NUMERIC(10, 2) NOT NULL, listings_count INT DEFAULT NULL, "timestamp" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_market_timestamp ON price_history (market_hash_name, "timestamp")');
        $this->addSql('CREATE INDEX idx_timestamp ON price_history ("timestamp")');
        $this->addSql('COMMENT ON COLUMN price_history."timestamp" IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('DROP TABLE sale_history');
        $this->addSql('ALTER TABLE sales_stats DROP sales_count7d');
        $this->addSql('ALTER TABLE sales_stats DROP sales_count30d');
        $this->addSql('ALTER TABLE sales_stats DROP avg_sales_per_day');
        $this->addSql('ALTER TABLE sales_stats DROP last_sale_date');
        $this->addSql('ALTER TABLE sales_stats RENAME COLUMN last_sale_price TO current_price');
        $this->addSql('ALTER INDEX idx_market_hash_name RENAME TO idx_whitelisted_items_market_hash_name');
        $this->addSql('ALTER INDEX idx_market_hash_name RENAME TO idx_inventory_market_hash_name');
        $this->addSql('ALTER INDEX idx_status RENAME TO idx_inventory_status');
        $this->addSql('ALTER INDEX idx_market_hash_name RENAME TO idx_transactions_market_hash_name');
        $this->addSql('ALTER INDEX idx_status RENAME TO idx_transactions_status');
    }
}
