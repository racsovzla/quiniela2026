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
final class Version20260506025149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('CREATE TABLE fixture (id SERIAL NOT NULL, kickoff_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, home_score INT DEFAULT NULL, away_score INT DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, home_team_id INT NOT NULL, away_team_id INT NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX IDX_5E540EE9C4C13F6 ON fixture (home_team_id)');
            $this->addSql('CREATE INDEX IDX_5E540EE45185D02 ON fixture (away_team_id)');

            $this->addSql('CREATE TABLE prediction (id SERIAL NOT NULL, predicted_home_score INT NOT NULL, predicted_away_score INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id INT NOT NULL, fixture_id INT NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX IDX_36396FC8A76ED395 ON prediction (user_id)');
            $this->addSql('CREATE INDEX IDX_36396FC8E524616D ON prediction (fixture_id)');
            $this->addSql('CREATE UNIQUE INDEX uniq_user_fixture ON prediction (user_id, fixture_id)');

            $this->addSql('CREATE TABLE team (id SERIAL NOT NULL, name VARCHAR(120) NOT NULL, code VARCHAR(3) NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_C4E0A61F5E237E06 ON team (name)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_C4E0A61F77153098 ON team (code)');

            $this->addSql('CREATE TABLE "user" (id SERIAL NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) DEFAULT NULL, name VARCHAR(120) NOT NULL, google_id VARCHAR(190) DEFAULT NULL, is_verified BOOLEAN NOT NULL, is_approved BOOLEAN NOT NULL, email_verification_code VARCHAR(6) DEFAULT NULL, email_verification_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D64976F5C865 ON "user" (google_id)');

            $this->addSql('CREATE TABLE messenger_messages (id BIGSERIAL NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');

            $this->addSql('ALTER TABLE fixture ADD CONSTRAINT FK_5E540EE9C4C13F6 FOREIGN KEY (home_team_id) REFERENCES team (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE fixture ADD CONSTRAINT FK_5E540EE45185D02 FOREIGN KEY (away_team_id) REFERENCES team (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE prediction ADD CONSTRAINT FK_36396FC8A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE prediction ADD CONSTRAINT FK_36396FC8E524616D FOREIGN KEY (fixture_id) REFERENCES fixture (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

            return;
        }

        $this->abortIf(!$platform instanceof MySQLPlatform, sprintf('Migration can only be executed safely on mysql or postgresql. Current platform: %s', $platform::class));

        $this->addSql('CREATE TABLE fixture (id INT AUTO_INCREMENT NOT NULL, kickoff_at DATETIME NOT NULL, home_score INT DEFAULT NULL, away_score INT DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, home_team_id INT NOT NULL, away_team_id INT NOT NULL, INDEX IDX_5E540EE9C4C13F6 (home_team_id), INDEX IDX_5E540EE45185D02 (away_team_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE prediction (id INT AUTO_INCREMENT NOT NULL, predicted_home_score INT NOT NULL, predicted_away_score INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, fixture_id INT NOT NULL, INDEX IDX_36396FC8A76ED395 (user_id), INDEX IDX_36396FC8E524616D (fixture_id), UNIQUE INDEX uniq_user_fixture (user_id, fixture_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE team (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, code VARCHAR(3) NOT NULL, UNIQUE INDEX UNIQ_C4E0A61F5E237E06 (name), UNIQUE INDEX UNIQ_C4E0A61F77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) DEFAULT NULL, name VARCHAR(120) NOT NULL, google_id VARCHAR(190) DEFAULT NULL, is_verified TINYINT NOT NULL, is_approved TINYINT NOT NULL, email_verification_code VARCHAR(6) DEFAULT NULL, email_verification_expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), UNIQUE INDEX UNIQ_8D93D64976F5C865 (google_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fixture ADD CONSTRAINT FK_5E540EE9C4C13F6 FOREIGN KEY (home_team_id) REFERENCES team (id)');
        $this->addSql('ALTER TABLE fixture ADD CONSTRAINT FK_5E540EE45185D02 FOREIGN KEY (away_team_id) REFERENCES team (id)');
        $this->addSql('ALTER TABLE prediction ADD CONSTRAINT FK_36396FC8A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE prediction ADD CONSTRAINT FK_36396FC8E524616D FOREIGN KEY (fixture_id) REFERENCES fixture (id)');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE fixture DROP CONSTRAINT FK_5E540EE9C4C13F6');
            $this->addSql('ALTER TABLE fixture DROP CONSTRAINT FK_5E540EE45185D02');
            $this->addSql('ALTER TABLE prediction DROP CONSTRAINT FK_36396FC8A76ED395');
            $this->addSql('ALTER TABLE prediction DROP CONSTRAINT FK_36396FC8E524616D');
            $this->addSql('DROP TABLE fixture');
            $this->addSql('DROP TABLE prediction');
            $this->addSql('DROP TABLE team');
            $this->addSql('DROP TABLE "user"');
            $this->addSql('DROP TABLE messenger_messages');

            return;
        }

        $this->abortIf(!$platform instanceof MySQLPlatform, sprintf('Migration can only be executed safely on mysql or postgresql. Current platform: %s', $platform::class));

        $this->addSql('ALTER TABLE fixture DROP FOREIGN KEY FK_5E540EE9C4C13F6');
        $this->addSql('ALTER TABLE fixture DROP FOREIGN KEY FK_5E540EE45185D02');
        $this->addSql('ALTER TABLE prediction DROP FOREIGN KEY FK_36396FC8A76ED395');
        $this->addSql('ALTER TABLE prediction DROP FOREIGN KEY FK_36396FC8E524616D');
        $this->addSql('DROP TABLE fixture');
        $this->addSql('DROP TABLE prediction');
        $this->addSql('DROP TABLE team');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
