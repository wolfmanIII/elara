<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251217152850 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_token ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE api_token ADD last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE api_token ADD usage_count INT NOT NULL');
        $this->addSql('ALTER TABLE api_token ADD revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE api_token ADD name VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_token DROP created_at');
        $this->addSql('ALTER TABLE api_token DROP last_used_at');
        $this->addSql('ALTER TABLE api_token DROP usage_count');
        $this->addSql('ALTER TABLE api_token DROP revoked_at');
        $this->addSql('ALTER TABLE api_token DROP name');
        $this->addSql('CREATE INDEX document_chunk_embedding_hnsw ON document_chunk (embedding)');
    }
}
