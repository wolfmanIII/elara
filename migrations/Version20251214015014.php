<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251214015014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('ALTER TABLE document_file ADD indexed_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE document_file ADD CONSTRAINT FK_2B2BBA83AD26C973 FOREIGN KEY (indexed_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_2B2BBA83AD26C973 ON document_file (indexed_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE "user"');
        $this->addSql('ALTER TABLE document_file DROP CONSTRAINT FK_2B2BBA83AD26C973');
        $this->addSql('DROP INDEX IDX_2B2BBA83AD26C973');
        $this->addSql('ALTER TABLE document_file DROP indexed_by_id');
    }
}
