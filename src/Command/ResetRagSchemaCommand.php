<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\DocumentChunk;
use App\Entity\DocumentFile;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolsException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:reset-rag-schema',
    description: 'Drop completo delle tabelle RAG, pulizia migrazioni e ricreazione indice vettoriale.'
)]
final class ResetRagSchemaCommand extends Command
{
    private const HNSW_INDEX = 'document_chunk_embedding_hnsw';

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Filesystem $filesystem,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Esegue realmente il reset. Senza questo flag il comando si ferma.'
            )
            ->addOption(
                'purge-migrations',
                null,
                InputOption::VALUE_NONE,
                'Cancella tutti i file PHP presenti nella cartella migrations/.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('force')) {
            $output->writeln('<error>Devi specificare --force per eseguire il reset.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>→ Drop tabelle DocumentFile / DocumentChunk</info>');

        $schemaTool = new SchemaTool($this->em);
        $metadata   = [
            $this->em->getClassMetadata(DocumentChunk::class),
            $this->em->getClassMetadata(DocumentFile::class),
        ];

        try {
            $schemaTool->dropSchema($metadata);
        } catch (ToolsException $e) {
            $output->writeln('<comment>  (drop schema) ' . $e->getMessage() . '</comment>');
        }

        try {
            $schemaTool->createSchema($metadata);
        } catch (ToolsException $e) {
            $output->writeln('<error>Errore nella creazione dello schema: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $connection = $this->em->getConnection();

        $output->writeln('<info>→ Reset tabella doctrine_migration_versions</info>');
        try {
            $connection->executeStatement('DROP TABLE IF EXISTS doctrine_migration_versions');
        } catch (DbalException $e) {
            $output->writeln('<comment>  (drop doctrine_migration_versions) ' . $e->getMessage() . '</comment>');
        }

        $output->writeln('<info>→ Ricreo indice HNSW su document_chunk.embedding</info>');
        try {
            $connection->executeStatement(sprintf('DROP INDEX IF EXISTS %s', self::HNSW_INDEX));
            $connection->executeStatement(
                sprintf(
                    'CREATE INDEX %s ON document_chunk USING hnsw (embedding vector_cosine_ops)',
                    self::HNSW_INDEX
                )
            );
        } catch (DbalException $e) {
            $output->writeln('<error>Errore nella creazione dell\'indice HNSW: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('purge-migrations')) {
            $output->writeln('<info>→ Pulizia cartella migrations/</info>');
            $this->purgeMigrationsDir($output);
        }

        $output->writeln('<info>Schema RAG azzerato. Ora puoi eseguire make:migration e reindicizzare.</info>');

        return Command::SUCCESS;
    }

    private function purgeMigrationsDir(OutputInterface $output): void
    {
        $dir = $this->projectDir . '/migrations';

        if (!$this->filesystem->exists($dir)) {
            $output->writeln('  (salto) cartella migrations/ non trovata.');
            return;
        }

        $files = glob($dir . '/*.php') ?: [];
        if ($files === []) {
            $output->writeln('  Nessun file *.php da rimuovere.');
            return;
        }

        foreach ($files as $file) {
            $this->filesystem->remove($file);
            $output->writeln('  eliminato: ' . basename($file));
        }
    }
}
