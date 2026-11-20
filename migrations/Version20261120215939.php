<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120215939 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make group and project titles unique regardless of letter case (title_key)';
    }

    public function up(Schema $schema): void
    {
        // Fails on purpose if two existing titles only differ by letter case: rename one of them first.
        foreach (['app_group' => 'UNIQ_BB13C908549E938C', 'project' => 'UNIQ_2FB3D0EE549E938C'] as $table => $index) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD title_key VARCHAR(512) DEFAULT NULL', $table));
            $this->addSql(\sprintf('UPDATE %s SET title_key = LOWER(title)', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ALTER title_key SET NOT NULL', $table));
            $this->addSql(\sprintf('CREATE UNIQUE INDEX %s ON %s (title_key)', $index, $table));
        }
        $this->addSql('DROP INDEX uniq_bb13c9082b36786b');
        $this->addSql('DROP INDEX uniq_2fb3d0ee2b36786b');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_BB13C908549E938C');
        $this->addSql('ALTER TABLE app_group DROP title_key');
        $this->addSql('CREATE UNIQUE INDEX uniq_bb13c9082b36786b ON app_group (title)');
        $this->addSql('DROP INDEX UNIQ_2FB3D0EE549E938C');
        $this->addSql('ALTER TABLE project DROP title_key');
        $this->addSql('CREATE UNIQUE INDEX uniq_2fb3d0ee2b36786b ON project (title)');
    }
}
