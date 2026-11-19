<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119214718 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add role and enabled flag to app_user';
    }

    public function up(Schema $schema): void
    {
        // existing rows get the defaults, which are dropped again to match the mapping
        $this->addSql("ALTER TABLE app_user ADD role VARCHAR(16) DEFAULT 'ROLE_USER' NOT NULL");
        $this->addSql('ALTER TABLE app_user ADD enabled BOOLEAN DEFAULT TRUE NOT NULL');
        $this->addSql('ALTER TABLE app_user ALTER role DROP DEFAULT');
        $this->addSql('ALTER TABLE app_user ALTER enabled DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP role');
        $this->addSql('ALTER TABLE app_user DROP enabled');
    }
}
