<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\Index\FileIndexStatus;
use App\Model\Index\IndexedFileResult;
use App\Service\DocsIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:index-docs',
    description: 'Indicizza i documenti nel database (chunk + embedding).',
)]
final class IndexDocsCommand extends Command
{
    private const ROOT_DIR = 'var/knowledge';

    public function __construct(
        private readonly DocsIndexer $indexer,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force-reindex', null, InputOption::VALUE_NONE, 'Forza la reindicizzazione anche se l\'hash è invariato')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula senza modificare il database')
            ->addOption('test-mode', null, InputOption::VALUE_NONE, 'Non chiama il modello esterno, utile per test')
            ->addOption('offline-fallback', null, InputOption::VALUE_REQUIRED, 'Usa embedding fake in caso di errore col modello', true)
            ->addOption('path', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Limita a questi sotto-path relativi')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = microtime(true);

        $forceReindex    = (bool) $input->getOption('force-reindex');
        $dryRun          = (bool) $input->getOption('dry-run');
        $testMode        = (bool) $input->getOption('test-mode');
        $offlineFallback = (bool) $input->getOption('offline-fallback');
        $pathsFilter     = (array) $input->getOption('path');

        $rootDir = $this->projectDir . DIRECTORY_SEPARATOR . self::ROOT_DIR;

        $output->writeln(sprintf(
            '<info>Indicizzazione documenti in %s</info>',
            $rootDir,
        ));

        $output->writeln(sprintf(
            '  force-reindex: %s, dry-run: %s, test-mode: %s, offline-fallback: %s',
            $forceReindex ? 'si' : 'no',
            $dryRun ? 'si' : 'no',
            $testMode ? 'si' : 'no',
            $offlineFallback ? 'si' : 'no',
        ));

        if ($pathsFilter !== []) {
            $output->writeln('  path filter:');
            foreach ($pathsFilter as $f) {
                $output->writeln('    - ' . $f);
            }
        }

        $output->writeln('');

        $progressBar = null;

        $summary = $this->indexer->indexDirectory(
            $rootDir,
            forceReindex: $forceReindex,
            dryRun: $dryRun,
            testMode: $testMode,
            offlineFallback: $offlineFallback,
            pathsFilter: $pathsFilter,
            excludedDirs: ['.git', 'node_modules', 'vendor'],
            excludedNamePatterns: ['*.tmp', '~$*', '*.swp'],

            // Callback chiamata all'inizio con il numero totale di file processabili
            onStart: function (int $total) use (&$progressBar, $output) {
                if ($total === 0) {
                    $output->writeln('<comment>Nessun file da processare.</comment>');
                    return;
                }

                $progressBar = new ProgressBar($output, $total);
                $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% - %message%');
                $progressBar->setMessage('In attesa...', 'message');
                $progressBar->start();
            },

            // Callback chiamata dopo ogni file
            onFileProcessed: function (IndexedFileResult $fileResult, int $current, int $total) use (&$progressBar) {
                if ($progressBar === null) {
                    return;
                }

                // Mostra il path relativo del file che è stato appena indicizzato/skippato
                $progressBar->setMessage($fileResult->relativePath, 'message');
                $progressBar->advance();
            }
        );

        if ($progressBar !== null) {
            $progressBar->finish();
            $output->writeln('');
        }

        // Riepilogo finale
        $elapsedSeconds = microtime(true) - $startedAt;

        $output->writeln(sprintf(
            '<comment>Trovati: %d file | Processati: %d | Indicizzati: %d | Skippati: %d | Falliti: %d | Tempo: %.2fs</comment>',
            $summary->totalFilesFound,
            $summary->totalProcessed,
            $summary->totalIndexed,
            $summary->totalSkipped,
            $summary->totalFailed,
            $elapsedSeconds,
        ));

        // Dettaglio per file in modalità verbose
        if ($output->isVerbose()) {
            $output->writeln(sprintf(
                '<info>Durata totale:</info> %s',
                $this->formatDuration($elapsedSeconds)
            ));
            foreach ($summary->files as $fileResult) {
                $statusLabel = match ($fileResult->status) {
                    FileIndexStatus::INDEXED_OK          => '<info>INDEXED</info>',
                    FileIndexStatus::INDEXED_WITH_ERRORS => '<comment>INDEXED*</comment>',
                    FileIndexStatus::SKIPPED_UNCHANGED   => '<comment>SKIPPED (unchanged)</comment>',
                    FileIndexStatus::SKIPPED_EXCLUDED    => '<comment>SKIPPED (excluded)</comment>',
                    FileIndexStatus::FAILED              => '<error>FAILED</error>',
                };

                $extra = $fileResult->errorMessage ? ' - ' . $fileResult->errorMessage : '';

                $output->writeln(sprintf(
                    '%s - %s (%d chunks)%s',
                    $statusLabel,
                    $fileResult->relativePath,
                    $fileResult->chunksCount,
                    $extra
                ));
            }
        }

        return $summary->totalFailed > 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    private function formatDuration(float $seconds): string
    {
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $secs = $seconds - ($hours * 3600) - ($minutes * 60);

        return sprintf('%02d:%02d:%05.2f', $hours, $minutes, $secs);
    }
}
