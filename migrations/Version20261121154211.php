<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261121154211 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE judge_setting ALTER model TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE skill ADD lock_version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE testcase ADD lock_version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE judge_setting ALTER model TYPE VARCHAR(100)');
        $this->addSql('ALTER TABLE skill DROP lock_version');
        $this->addSql('ALTER TABLE testcase DROP lock_version');
    }
}
