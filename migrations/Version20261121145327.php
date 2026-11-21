<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121145327 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the judge_setting table for the provider and model of the LLM judge.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE judge_setting (id INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, provider VARCHAR(32) NOT NULL, model VARCHAR(100) NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE judge_setting');
    }
}
