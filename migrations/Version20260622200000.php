<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore scheduled status for fixtures marked postponed/suspended/rescheduled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE fixture SET status = 'scheduled' WHERE status IN ('postponed', 'suspended', 'rescheduled')");
    }

    public function down(Schema $schema): void
    {
        // Cannot reliably restore previous non-standard statuses.
    }
}
