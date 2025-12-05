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
            //$chunks = $this->splitIntoChunks($text, 1400);
            $chunks = $this->chunkText($text);
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
                    // Embedding finto, niente AI (puoi tenere 768 per compatibilità col DB)
                    $embedding = $this->fakeEmbeddingFromText($chunkText, 768);
                } else {
                    try {
                        // Usa il backend configurato (Ollama/OpenAI/altro)
                        $embedding = $this->ai->embed($chunkText);
                    } catch (\Throwable $e) {
                        if ($offlineFallback) {
                            $output->writeln("  -> errore embedding, uso fallback locale: " . $e->getMessage());
                            $embedding = $this->fakeEmbeddingFromText($chunkText, 768);
                        } else {
                            $output->writeln("  -> errore embedding: " . $e->getMessage());
                            // Se preferisci saltare solo questo chunk:
                            // continue;
                            // Per ora continuiamo con embedding finto per non bucare l'indice:
                            $embedding = $this->fakeEmbeddingFromText($chunkText, 768);
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
     * Split semplice in chunk di ~maxLen caratteri,
     * tagliando su punto o spazio quando possibile
     * e cercando di NON spezzare parole.
     */
    private function splitIntoChunks(string $text, int $maxLen): array
    {
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        $chunks = [];
        $len    = mb_strlen($text, 'UTF-8');
        $offset = 0;

        if ($len === 0) {
            return [];
        }

        while ($offset < $len) {
            $remaining = $len - $offset;
            $length    = min($maxLen, $remaining);

            $slice = mb_substr($text, $offset, $length, 'UTF-8');

            // 1) prova ultimo punto nella slice
            $cut = mb_strrpos($slice, '.', 0, 'UTF-8');

            // 2) se niente punto, prova ultimo spazio
            if ($cut === false || $cut <= 0) {
                $cut = mb_strrpos($slice, ' ', 0, 'UTF-8');
            }

            // 3) se ancora niente, estendi fino al prossimo spazio globale
            if ($cut === false || $cut <= 0) {
                $nextSpacePos = mb_strpos($text, ' ', $offset + $length, 'UTF-8');

                if ($nextSpacePos !== false) {
                    $cut = $nextSpacePos - $offset; // taglia dopo la parola
                } else {
                    $cut = $remaining; // nessuno spazio → prendi tutto quello che resta
                }
            }

            $chunkText = trim(mb_substr($text, $offset, $cut, 'UTF-8'));
            if ($chunkText !== '') {
                $chunks[] = $chunkText;
            }

            $offset += $cut;
        }

        return $chunks;
    }

    /**
     * Algoritmo di chunking ottimizzato, che evita chunk troppo corti,
     * include overlap ed è UTF-8 safe. I paragrafi più lunghi di $max
     * vengono spezzati usando splitIntoChunks().
     */
    private function chunkText(
        string $text,
        int $min = 300,
        int $target = 800,
        int $max = 1000,
        int $overlap = 150
    ): array {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // Splitta per paragrafi (due o più newline consecutivi)
        $parts = preg_split("/\R{2,}/u", $text, -1, PREG_SPLIT_NO_EMPTY);

        $chunks = [];
        $buffer = '';

        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }

            $pLen = mb_strlen($p, 'UTF-8');

            // 1) Paragrafo singolo più lungo di $max → spezzalo con splitIntoChunks
            if ($pLen > $max) {
                // Flush del buffer corrente
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }

                // Riusa il vecchio algoritmo a frasi
                $subChunks = $this->splitIntoChunks($p, $max);
                foreach ($subChunks as $sc) {
                    if (trim($sc) !== '') {
                        $chunks[] = $sc;
                    }
                }
                continue;
            }

            // 2) Paragrafo corto: gestito col buffer/min/target/max

            $bufferLen = mb_strlen($buffer, 'UTF-8');

            // Se il paragrafo è troppo corto → accumula nel buffer
            if ($pLen < $min) {
                $buffer .= ($buffer !== '' ? ' ' : '') . $p;
                continue;
            }

            // Se buffer + paragrafo superano max → chiudi chunk corrente e riparti
            if ($bufferLen + $pLen > $max) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                }
                $buffer = $p;
                continue;
            }

            // Aggiungi al buffer
            $buffer .= ($buffer !== '' ? ' ' : '') . $p;

            // Se raggiungiamo il target → chiudiamo il chunk
            if (mb_strlen($buffer, 'UTF-8') >= $target) {
                $chunks[] = $buffer;
                $buffer = '';
            }
        }

        // Flush finale del buffer se rimasto qualcosa
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        // 3) Aggiungi overlap TRA CHUNK, ma basato su parole
        $final = [];
        $count = count($chunks);

        for ($i = 0; $i < $count; $i++) {
            $chunk = $chunks[$i];

            if ($i > 0 && $overlap > 0) {
                $prev   = $chunks[$i - 1];

                // prefix = ultime parole del chunk precedente
                $prefix = $this->buildWordOverlap($prev, $overlap);

                $chunk = $prefix . $chunk;
            }

            $chunk = $this->fixMissingSpaces($chunk);

            $chunk = trim($chunk);
            if ($chunk !== '') {
                $final[] = $chunk;
            }
        }

        return $final;
    }

    /**
     * Costruisce un overlap basato su parole (non su caratteri).
     * Prende le ultime parole del chunk precedente finché non
     * supera approssimativamente overlapChars caratteri.
     */
    private function buildWordOverlap(string $prev, int $overlapChars): string
    {
        $prev = trim($prev);
        if ($overlapChars <= 0 || $prev === '') {
            return '';
        }

        $words = preg_split('/\s+/', $prev, -1, PREG_SPLIT_NO_EMPTY);
        if (!$words || count($words) === 0) {
            return '';
        }

        $selected  = [];
        $totalLen  = 0;

        // parti dalla fine e risali
        for ($i = count($words) - 1; $i >= 0; $i--) {
            $w    = $words[$i];
            $wLen = mb_strlen($w, 'UTF-8');

            // +1 per lo spazio che aggiungeremo tra le parole
            if ($totalLen > 0) {
                $wLen += 1;
            }

            if ($totalLen + $wLen > $overlapChars && !empty($selected)) {
                break;
            }

            array_unshift($selected, $w);
            $totalLen += $wLen;

            if ($totalLen >= $overlapChars) {
                break;
            }
        }

        return implode(' ', $selected) . ' ';
    }

    private function fixMissingSpaces(string $text): string
    {
        // 1) Spazio dopo ., !, ?, ;, : se NON c'è già uno spazio
        // es: "dominanti:Carisma" -> "dominanti: Carisma"
        $text = preg_replace(
            '/([\.!?;:])([^\s])/u',
            '$1 $2',
            $text
        );

        // 2) Spazio tra parola ALL-CAPS e parola Capitalized attaccate
        // es: "MOTIVAZIONIRuolo" -> "MOTIVAZIONI Ruolo"
        //     "PSICOLOGICOEtà"   -> "PSICOLOGICO Età"
        $text = preg_replace(
            '/\b([A-ZÀ-ÖØ-Ý]{2,})([A-ZÀ-ÖØ-Ý][a-zà-öø-ÿ]+)/u',
            '$1 $2',
            $text
        );

        // 3) Spazio tra minuscola e maiuscola attaccate (caso generico)
        // es: "standard.Origini" -> "standard. Origini"
        $text = preg_replace(
            '/([\p{Ll}])([\p{Lu}])/u',
            '$1 $2',
            $text
        );

        return $text;
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
