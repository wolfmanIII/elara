<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Estende api_token con metadati di audit (created_at, usage, revoca, label).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NOW() NOT NULL');
        $this->addSql('ALTER TABLE api_token ADD last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE api_token ADD usage_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE api_token ADD revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE api_token ADD name VARCHAR(120) DEFAULT NULL');
        $this->addSql('UPDATE api_token SET created_at = NOW() WHERE created_at IS NULL');
        $this->addSql('ALTER TABLE api_token ALTER COLUMN created_at DROP DEFAULT');
        $this->addSql('ALTER TABLE api_token ALTER COLUMN usage_count SET DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP created_at');
        $this->addSql('ALTER TABLE api_token DROP last_used_at');
        $this->addSql('ALTER TABLE api_token DROP usage_count');
        $this->addSql('ALTER TABLE api_token DROP revoked_at');
        $this->addSql('ALTER TABLE api_token DROP name');
    }
}
