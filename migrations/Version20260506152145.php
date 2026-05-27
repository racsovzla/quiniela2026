<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260506152145 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('CREATE TABLE tournament_group (id SERIAL NOT NULL, code VARCHAR(2) NOT NULL, name VARCHAR(120) NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_AC96D4DC77153098 ON tournament_group (code)');
            $this->addSql('ALTER TABLE fixture ADD group_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE fixture ADD CONSTRAINT FK_5E540EEFE54D947 FOREIGN KEY (group_id) REFERENCES tournament_group (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE INDEX IDX_5E540EEFE54D947 ON fixture (group_id)');
            $this->addSql('ALTER TABLE team ADD group_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE team ADD CONSTRAINT FK_C4E0A61FFE54D947 FOREIGN KEY (group_id) REFERENCES tournament_group (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('CREATE INDEX IDX_C4E0A61FFE54D947 ON team (group_id)');

            return;
        }

        $this->abortIf(!$platform instanceof MySQLPlatform, sprintf('Migration can only be executed safely on mysql or postgresql. Current platform: %s', $platform::class));

        $this->addSql('CREATE TABLE tournament_group (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(2) NOT NULL, name VARCHAR(120) NOT NULL, UNIQUE INDEX UNIQ_AC96D4DC77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fixture ADD group_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fixture ADD CONSTRAINT FK_5E540EEFE54D947 FOREIGN KEY (group_id) REFERENCES tournament_group (id)');
        $this->addSql('CREATE INDEX IDX_5E540EEFE54D947 ON fixture (group_id)');
        $this->addSql('ALTER TABLE team ADD group_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE team ADD CONSTRAINT FK_C4E0A61FFE54D947 FOREIGN KEY (group_id) REFERENCES tournament_group (id)');
        $this->addSql('CREATE INDEX IDX_C4E0A61FFE54D947 ON team (group_id)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE fixture DROP CONSTRAINT FK_5E540EEFE54D947');
            $this->addSql('DROP INDEX IDX_5E540EEFE54D947');
            $this->addSql('ALTER TABLE fixture DROP group_id');
            $this->addSql('ALTER TABLE team DROP CONSTRAINT FK_C4E0A61FFE54D947');
            $this->addSql('DROP INDEX IDX_C4E0A61FFE54D947');
            $this->addSql('ALTER TABLE team DROP group_id');
            $this->addSql('DROP TABLE tournament_group');

            return;
        }

        $this->abortIf(!$platform instanceof MySQLPlatform, sprintf('Migration can only be executed safely on mysql or postgresql. Current platform: %s', $platform::class));

        $this->addSql('DROP TABLE tournament_group');
        $this->addSql('ALTER TABLE fixture DROP FOREIGN KEY FK_5E540EEFE54D947');
        $this->addSql('DROP INDEX IDX_5E540EEFE54D947 ON fixture');
        $this->addSql('ALTER TABLE fixture DROP group_id');
        $this->addSql('ALTER TABLE team DROP FOREIGN KEY FK_C4E0A61FFE54D947');
        $this->addSql('DROP INDEX IDX_C4E0A61FFE54D947 ON team');
        $this->addSql('ALTER TABLE team DROP group_id');
    }
}
