<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251203213525 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE document_chunk (id UUID NOT NULL, chunk_index INT NOT NULL, content TEXT NOT NULL, embedding vector(768) NOT NULL, file_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FCA7075C93CB796C ON document_chunk (file_id)');
        $this->addSql('CREATE TABLE document_file (id UUID NOT NULL, path VARCHAR(500) NOT NULL, extension VARCHAR(20) NOT NULL, hash VARCHAR(64) NOT NULL, indexed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE document_chunk ADD CONSTRAINT FK_FCA7075C93CB796C FOREIGN KEY (file_id) REFERENCES document_file (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE document_chunk DROP CONSTRAINT FK_FCA7075C93CB796C');
        $this->addSql('DROP TABLE document_chunk');
        $this->addSql('DROP TABLE document_file');
    }
}
