<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Testcase versions store output criteria for the LLM judge instead of an expected output.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE testcase_version RENAME COLUMN expected_output TO output_criteria');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE testcase_version RENAME COLUMN output_criteria TO expected_output');
    }
}
