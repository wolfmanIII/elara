<?php

namespace App\Command;

use App\AI\AiClientInterface;
use App\Entity\DocumentChunk;
use App\Entity\DocumentFile;
use App\Service\DocumentTextExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:index-docs')]
class IndexDocsCommand extends Command
{
    /**
     * Directory da indicizzare (relativa al progetto).
     */
    private const ROOT_DIR = __DIR__ . '/../../var/knowledge';

    /**
     * Estensioni supportate.
     */
    private array $extensions = ['pdf', 'md', 'odt', 'docx'];

    /**
     * Sottocartelle da escludere (match su path relativi).
     */
    private array $excludedDirs = [
        'images',
        'img',
        'tmp',
        '.git',
        '.idea',
    ];

    /**
     * Pattern filename da escludere.
     */
    private array $excludedNamePatterns = [
        '/^~.*$/',
        '/^\.~lock\..*/',
        '/^\.gitkeep$/',
        '/^\.DS_Store$/',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private DocumentTextExtractor  $extractor,
        private AiClientInterface      $ai,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Indicizza i documenti (PDF/MD/ODT/DOCX) in var/knowledge, genera embeddings e salva su Postgres (pgvector).')
            ->addOption(
                'force-reindex',
                null,
                InputOption::VALUE_NONE,
                'Ignora hash e reindicizza tutto, anche se il file non è cambiato.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulazione: nessuna scrittura su DB e nessuna chiamata AI.'
            )
            ->addOption(
                'test-mode',
                null,
                InputOption::VALUE_NONE,
                'Usa embeddings finti (derivati dal testo) invece di chiamare il backend AI.'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Sotto-percorsi da indicizzare (es: manuali, log/2025). Puoi usare più --path.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rootDir = self::ROOT_DIR;

        if (!is_dir($rootDir)) {
            $output->writeln('<error>Cartella non trovata: ' . $rootDir . '</error>');
            return Command::FAILURE;
        }

        $forceReindex = (bool) $input->getOption('force-reindex');
        $dryRun       = (bool) $input->getOption('dry-run');

        // test-mode può essere attivato da option o da env
        $testMode =
            (bool) $input->getOption('test-mode')
            || (($_ENV['APP_AI_TEST_MODE'] ?? 'false') === 'true');

        $offlineFallback =
            (($_ENV['APP_AI_OFFLINE_FALLBACK'] ?? 'true') === 'true');

        /** @var string[] $pathsFilter */
        $pathsFilter = $input->getOption('path') ?? [];
        $pathsFilter = array_map(static fn(string $p) => trim($p, '/'), $pathsFilter);

        if (!empty($pathsFilter)) {
            $output->writeln('<info>Filtro path:</info> ' . implode(', ', $pathsFilter));
        }
        if ($forceReindex) {
            $output->writeln('<comment>--force-reindex attivo</comment>');
        }
        if ($dryRun) {
            $output->writeln('<comment>--dry-run: nessuna scrittura su DB</comment>');
        }
        if ($testMode) {
            $output->writeln('<comment>--test-mode: embeddings finti, nessuna chiamata AI</comment>');
        }

        // ---------------------------------------------------------------------
        // 1) Scansione ricorsiva dei file
        // ---------------------------------------------------------------------
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];

        foreach ($iterator as $filePath => $info) {
            if (!$info->isFile()) {
                continue;
            }

            $ext = strtolower($info->getExtension());
            if (!in_array($ext, $this->extensions, true)) {
                continue;
            }

            $relPath = substr($filePath, strlen($rootDir) + 1);
            $dirName = trim(dirname($relPath), '.');

            if ($this->isInExcludedDir($dirName)) {
                continue;
            }

            if ($this->isExcludedName($info->getFilename())) {
                continue;
            }

            if (!empty($pathsFilter) && !$this->matchesPathsFilter($relPath, $pathsFilter)) {
                continue;
            }

            $files[] = $filePath;
        }

        if (!$files) {
            $output->writeln('<comment>Nessun file da indicizzare.</comment>');
            return Command::SUCCESS;
        }

        // ---------------------------------------------------------------------
        // 2) Barra di progresso
        // ---------------------------------------------------------------------
        $progressBar = null;
        if ($output->isVerbose()) {
            $progressBar = new ProgressBar($output, count($files));
            $progressBar->start();
        }

        $fileRepo = $this->em->getRepository(DocumentFile::class);

        // ---------------------------------------------------------------------
        // 3) Loop sui file
        // ---------------------------------------------------------------------
        foreach ($files as $file) {
            $relPath   = substr($file, strlen($rootDir) + 1);
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $fileHash  = hash_file('sha256', $file);

            $output->writeln("\n[FILE] $relPath");

            /** @var DocumentFile|null $fileEntity */
            $fileEntity = $fileRepo->findOneBy(['path' => $relPath]);

            // Se il file è già presente e l'hash non è cambiato, possiamo saltare
            if ($fileEntity !== null) {
                $oldHash = $fileEntity->getHash();
                if (!$forceReindex && !$dryRun && !$testMode && $oldHash === $fileHash) {
                    $output->writeln("  -> hash invariato, salto");
                    if ($progressBar) {
                        $progressBar->advance();
                    }
                    continue;
                }
            }

            // Estrazione testo
            $output->writeln("  -> estrazione testo...");
            $text = $this->extractor->extract($file);

            if ($text === null || $text === '') {
                $output->writeln("  -> nessun testo estratto, salto");
                if ($progressBar) {
                    $progressBar->advance();
                }
                continue;
            }

            $len = mb_strlen($text);
            $output->writeln("  -> testo estratto, len = $len caratteri");

            // Split in chunk
            $output->writeln("  -> split in chunk...");
            $chunks = $this->splitIntoChunks($text, 1000);
            $now    = new \DateTimeImmutable();

            // DRY-RUN → solo log, niente DB / niente embeddings reali
            if ($dryRun) {
                $approxTokens = (int) ($len / 4);
                $output->writeln("  [dry-run] $relPath → " . count($chunks)
                    . " chunk (~$approxTokens token)");
                if ($progressBar) {
                    $progressBar->advance();
                }
                continue;
            }

            // Creiamo o aggiorniamo DocumentFile
            if ($fileEntity === null) {
                $fileEntity = (new DocumentFile())
                    ->setPath($relPath)
                    ->setExtension($extension)
                    ->setHash($fileHash)
                    ->setIndexedAt($now);
                $this->em->persist($fileEntity);
                $output->writeln("  -> creato record DocumentFile");
            } else {
                $fileEntity
                    ->setExtension($extension)
                    ->setHash($fileHash)
                    ->setIndexedAt($now);
                $output->writeln("  -> aggiornato record DocumentFile");
            }

            // Cancella eventuali chunk già esistenti per questo file
            $output->writeln("  -> cancello chunk esistenti (se presenti)...");
            $this->em->createQueryBuilder()
                ->delete(DocumentChunk::class, 'c')
                ->where('c.file = :file')
                ->setParameter('file', $fileEntity)
                ->getQuery()
                ->execute();

            $output->writeln("  -> creo chunk + embedding (test-mode: " . ($testMode ? 'sì' : 'no') . ")");

            // Creazione nuovi chunk
            foreach ($chunks as $index => $chunkText) {
                $embedding = null;

                if ($testMode) {
                    // Embedding finto, niente AI (puoi tenere 1536 per compatibilità col DB)
                    $embedding = $this->fakeEmbeddingFromText($chunkText, 1536);
                } else {
                    try {
                        // Usa il backend configurato (Ollama/OpenAI/altro)
                        $embedding = $this->ai->embed($chunkText);
                    } catch (\Throwable $e) {
                        if ($offlineFallback) {
                            $output->writeln("  -> errore embedding, uso fallback locale: " . $e->getMessage());
                            $embedding = $this->fakeEmbeddingFromText($chunkText, 1536);
                        } else {
                            $output->writeln("  -> errore embedding: " . $e->getMessage());
                            // Se preferisci saltare solo questo chunk:
                            // continue;
                            // Per ora continuiamo con embedding finto per non bucare l'indice:
                            $embedding = $this->fakeEmbeddingFromText($chunkText, 1536);
                        }
                    }
                }

                $chunk = (new DocumentChunk())
                    ->setFile($fileEntity)
                    ->setChunkIndex($index)
                    ->setContent($chunkText)
                    ->setEmbedding($embedding);

                $this->em->persist($chunk);
            }

            $this->em->flush();
            $this->em->clear();

            $output->writeln("  -> indicizzazione completata per $relPath (" . count($chunks) . " chunk)");

            if ($progressBar) {
                $progressBar->advance();
            }
        }

        if ($progressBar) {
            $progressBar->finish();
            $output->writeln('');
        }

        $output->writeln('<info>Indicizzazione completata.</info>');
        return Command::SUCCESS;
    }

    // =====================================================================
    // METODI DI SUPPORTO
    // =====================================================================

    /**
     * Split molto semplice in chunk di max $maxLen caratteri, cercando di tagliare
     * su punto o spazio quando possibile.
     */
    private function splitIntoChunks(string $text, int $maxLen): array
    {
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        $chunks = [];
        $len    = mb_strlen($text);
        $offset = 0;

        if ($len === 0) {
            return [];
        }

        while ($offset < $len) {
            $remaining = $len - $offset;
            $length    = min($maxLen, $remaining);

            $slice = mb_substr($text, $offset, $length);

            $cut = mb_strrpos($slice, '.');
            if ($cut === false || $cut < (int)($length * 0.3)) {
                $cut = mb_strrpos($slice, ' ');
            }
            if ($cut === false || $cut <= 0) {
                $cut = $length;
            }

            $chunkText = trim(mb_substr($text, $offset, $cut));
            if ($chunkText !== '') {
                $chunks[] = $chunkText;
            }

            $offset += $cut;
        }

        return $chunks;
    }

    private function isInExcludedDir(string $dirName): bool
    {
        if ($dirName === '.' || $dirName === '') {
            return false;
        }

        $segments = explode(DIRECTORY_SEPARATOR, $dirName);
        foreach ($segments as $seg) {
            if (in_array($seg, $this->excludedDirs, true)) {
                return true;
            }
        }

        return false;
    }

    private function isExcludedName(string $filename): bool
    {
        foreach ($this->excludedNamePatterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPathsFilter(string $relPath, array $filter): bool
    {
        $rel = ltrim($relPath, '/');

        foreach ($filter as $f) {
            $f = trim($f, '/');

            if ($rel === $f) {
                return true;
            }

            if (str_starts_with($rel, $f . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Genera un embedding finto deterministico, usato in test-mode o fallback.
     */
    private function fakeEmbeddingFromText(string $text, int $dimensions): array
    {
        $hash   = hash('sha256', $text, true);
        $vector = [];

        for ($i = 0; $i < $dimensions; $i++) {
            $b = ord($hash[$i % 32]);
            $vector[] = ($b / 128.0) - 1.0; // range ~[-1, 1]
        }

        return $vector;
    }
}
