<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Defense in depth for the checkout path: the conditional UPDATE in
 * ProductRepository::reserveStock() is the primary oversell guard, this
 * constraint turns any future unconditional decrement into a hard error
 * instead of a silent negative stock.
 */
final class Version20260905120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CHECK (stock >= 0) on product';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD CONSTRAINT chk_product_stock_non_negative CHECK (stock >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP CONSTRAINT chk_product_stock_non_negative');
    }
}
