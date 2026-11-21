<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121033832 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Testcases belong to many projects (testcase_project) instead of one (testcase.project_id).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE testcase_project (testcase_id INT NOT NULL, project_id INT NOT NULL, PRIMARY KEY (testcase_id, project_id))');
        $this->addSql('CREATE INDEX IDX_81D32CA5A1FA3458 ON testcase_project (testcase_id)');
        $this->addSql('CREATE INDEX IDX_81D32CA5166D1F9C ON testcase_project (project_id)');
        $this->addSql('ALTER TABLE testcase_project ADD CONSTRAINT FK_81D32CA5A1FA3458 FOREIGN KEY (testcase_id) REFERENCES testcase (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE testcase_project ADD CONSTRAINT FK_81D32CA5166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('INSERT INTO testcase_project (testcase_id, project_id) SELECT id, project_id FROM testcase WHERE project_id IS NOT NULL');
        $this->addSql('ALTER TABLE testcase DROP CONSTRAINT fk_4c1e5c39166d1f9c');
        $this->addSql('DROP INDEX idx_4c1e5c39166d1f9c');
        $this->addSql('ALTER TABLE testcase DROP project_id');
    }

    /** Lossy: a testcase with several projects keeps only the one with the lowest id. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE testcase ADD project_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE testcase ADD CONSTRAINT fk_4c1e5c39166d1f9c FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_4c1e5c39166d1f9c ON testcase (project_id)');
        $this->addSql('UPDATE testcase SET project_id = (SELECT MIN(tp.project_id) FROM testcase_project tp WHERE tp.testcase_id = testcase.id)');
        $this->addSql('ALTER TABLE testcase_project DROP CONSTRAINT FK_81D32CA5A1FA3458');
        $this->addSql('ALTER TABLE testcase_project DROP CONSTRAINT FK_81D32CA5166D1F9C');
        $this->addSql('DROP TABLE testcase_project');
    }
}
