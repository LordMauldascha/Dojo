<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261122033127 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the ai_helper_setting table for the provider and model of the AI helper.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_helper_setting (id INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, provider VARCHAR(32) NOT NULL, model VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_helper_setting');
    }
}
