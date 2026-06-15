<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260614120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password reset token fields to user';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE "user" ADD password_reset_token VARCHAR(64) DEFAULT NULL');
            $this->addSql('ALTER TABLE "user" ADD password_reset_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

            return;
        }

        $this->abortIf(!$platform instanceof MySQLPlatform, sprintf('Migration can only be executed safely on mysql or postgresql. Current platform: %s', $platform::class));

        $this->addSql('ALTER TABLE user ADD password_reset_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD password_reset_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE "user" DROP password_reset_token');
            $this->addSql('ALTER TABLE "user" DROP password_reset_expires_at');

            return;
        }

        $this->abortIf(!$platform instanceof MySQLPlatform, sprintf('Migration can only be executed safely on mysql or postgresql. Current platform: %s', $platform::class));

        $this->addSql('ALTER TABLE user DROP password_reset_token');
        $this->addSql('ALTER TABLE user DROP password_reset_expires_at');
    }
}
