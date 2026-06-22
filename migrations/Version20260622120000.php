<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add knockout stage, penalty scores, and FIFA match id for fixtures and predictions';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql("ALTER TABLE fixture ADD stage VARCHAR(10) DEFAULT 'group' NOT NULL");
            $this->addSql('ALTER TABLE fixture ADD penalty_home_score INT DEFAULT NULL');
            $this->addSql('ALTER TABLE fixture ADD penalty_away_score INT DEFAULT NULL');
            $this->addSql('ALTER TABLE fixture ADD fifa_match_id VARCHAR(20) DEFAULT NULL');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_5E540EE_FIFA_MATCH ON fixture (fifa_match_id)');
            $this->addSql('ALTER TABLE prediction ADD predicted_penalty_home_score INT DEFAULT NULL');
            $this->addSql('ALTER TABLE prediction ADD predicted_penalty_away_score INT DEFAULT NULL');

            return;
        }

        $this->abortIf(!$platform instanceof MySQLPlatform, sprintf('Migration can only be executed safely on mysql or postgresql. Current platform: %s', $platform::class));

        $this->addSql("ALTER TABLE fixture ADD stage VARCHAR(10) DEFAULT 'group' NOT NULL");
        $this->addSql('ALTER TABLE fixture ADD penalty_home_score INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fixture ADD penalty_away_score INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fixture ADD fifa_match_id VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E540EE_FIFA_MATCH ON fixture (fifa_match_id)');
        $this->addSql('ALTER TABLE prediction ADD predicted_penalty_home_score INT DEFAULT NULL');
        $this->addSql('ALTER TABLE prediction ADD predicted_penalty_away_score INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('DROP INDEX UNIQ_5E540EE_FIFA_MATCH');
            $this->addSql('ALTER TABLE fixture DROP stage');
            $this->addSql('ALTER TABLE fixture DROP penalty_home_score');
            $this->addSql('ALTER TABLE fixture DROP penalty_away_score');
            $this->addSql('ALTER TABLE fixture DROP fifa_match_id');
            $this->addSql('ALTER TABLE prediction DROP predicted_penalty_home_score');
            $this->addSql('ALTER TABLE prediction DROP predicted_penalty_away_score');

            return;
        }

        $this->abortIf(!$platform instanceof MySQLPlatform, sprintf('Migration can only be executed safely on mysql or postgresql. Current platform: %s', $platform::class));

        $this->addSql('DROP INDEX UNIQ_5E540EE_FIFA_MATCH ON fixture');
        $this->addSql('ALTER TABLE fixture DROP stage');
        $this->addSql('ALTER TABLE fixture DROP penalty_home_score');
        $this->addSql('ALTER TABLE fixture DROP penalty_away_score');
        $this->addSql('ALTER TABLE fixture DROP fifa_match_id');
        $this->addSql('ALTER TABLE prediction DROP predicted_penalty_home_score');
        $this->addSql('ALTER TABLE prediction DROP predicted_penalty_away_score');
    }
}
