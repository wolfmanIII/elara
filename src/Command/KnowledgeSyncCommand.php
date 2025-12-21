<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:knowledge:sync',
    description: 'Copia docs/ e README.md dentro var/knowledge per l\'indicizzazione.',
)]
final class KnowledgeSyncCommand extends Command
{
    private const DEFAULT_TARGET = 'var/knowledge';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly Filesystem $fs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'source',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Percorsi sorgente aggiuntivi (file o cartelle) da copiare oltre a docs/ e README.md',
                []
            )
            ->addOption(
                'target',
                null,
                InputOption::VALUE_OPTIONAL,
                'Percorso di destinazione (default: var/knowledge)',
                self::DEFAULT_TARGET
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $docsPath = $this->projectDir . DIRECTORY_SEPARATOR . 'docs';
        $readmePath = $this->projectDir . DIRECTORY_SEPARATOR . 'README.md';
        $extraSources = (array) $input->getOption('source');
        $target = $input->getOption('target');
        $targetPath = str_starts_with($target, DIRECTORY_SEPARATOR)
            ? $target
            : $this->projectDir . DIRECTORY_SEPARATOR . $target;

        $io->title('Sincronizzazione knowledge base');
        $io->text(sprintf('Origine docs: %s', $docsPath));
        $io->text(sprintf('Origine README: %s', $readmePath));
        if ($extraSources !== []) {
            foreach ($extraSources as $src) {
                $io->text(sprintf('Origine extra: %s', $this->resolvePath($src)));
            }
        }
        $io->text(sprintf('Destinazione: %s', $targetPath));
        $io->newLine();

        try {
            $this->fs->mkdir($targetPath);

            if (is_dir($docsPath)) {
                $this->fs->mirror($docsPath, $targetPath, null, [
                    'override' => true,
                    'delete' => false,
                    'copy_on_windows' => true,
                ]);
                $io->success('Cartella docs copiata.');
            } else {
                $io->warning('Cartella docs/ non trovata: niente da copiare.');
            }

            if (is_file($readmePath)) {
                $this->fs->copy($readmePath, $targetPath . DIRECTORY_SEPARATOR . 'README.md', true);
                $io->success('README.md copiato.');
            } else {
                $io->warning('README.md non trovato: saltato.');
            }

            foreach ($extraSources as $src) {
                $resolved = $this->resolvePath($src);
                if (is_dir($resolved)) {
                    $this->fs->mirror($resolved, $targetPath, null, [
                        'override' => true,
                        'delete' => false,
                        'copy_on_windows' => true,
                    ]);
                    $io->success(sprintf('Cartella extra copiata: %s', $resolved));
                } elseif (is_file($resolved)) {
                    $destFile = $targetPath . DIRECTORY_SEPARATOR . basename($resolved);
                    $this->fs->copy($resolved, $destFile, true);
                    $io->success(sprintf('File extra copiato: %s', $resolved));
                } else {
                    $io->warning(sprintf('Percorso extra non trovato: %s', $src));
                }
            }
        } catch (\Throwable $e) {
            $io->error('Errore nella copia: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->writeln('<info>Sincronizzazione completata.</info>');

        return Command::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? $path
            : $this->projectDir . DIRECTORY_SEPARATOR . $path;
    }
}
