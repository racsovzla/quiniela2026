<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525021000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds payment_validated_at to user for payment-based scoring eligibility';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE "user" ADD payment_validated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

            return;
        }

        $this->abortIf(!$platform instanceof MySQLPlatform, sprintf('Migration can only be executed safely on mysql or postgresql. Current platform: %s', $platform::class));

        $this->addSql('ALTER TABLE `user` ADD payment_validated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE "user" DROP payment_validated_at');

            return;
        }

        $this->abortIf(!$platform instanceof MySQLPlatform, sprintf('Migration can only be executed safely on mysql or postgresql. Current platform: %s', $platform::class));

        $this->addSql('ALTER TABLE `user` DROP payment_validated_at');
    }
}
