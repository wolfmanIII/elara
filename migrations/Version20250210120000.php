<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250210120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add searchable flag to document_chunk for monitoring embedding fallback.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_chunk ADD searchable BOOLEAN DEFAULT TRUE NOT NULL');
        $this->addSql('UPDATE document_chunk SET searchable = TRUE WHERE searchable IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_chunk DROP searchable');
    }
}
