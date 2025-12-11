<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentChunk;
use App\Entity\DocumentFile;
use App\Model\Index\FileIndexStatus;
use App\Model\Index\IndexSummary;
use App\Model\Index\IndexedFileResult;
use Doctrine\ORM\EntityManagerInterface;
use App\AI\AiClientInterface;
use App\AI\GeminiClient;

/**
 * Service “puro” che si occupa di:
 * - trovare i file da indicizzare
 * - estrarre testo
 * - fare chunking
 * - calcolare embedding
 * - salvare DocumentFile + DocumentChunk
 *
 */
final class DocsIndexer
{
    private const HASH_NORMALIZER = 4294967295;

    private int $embeddingDimension;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentTextExtractor $extractor,
        private readonly ChunkingService $chunking,
        private readonly AiClientInterface $embeddingClient,
    ) {
        $this->embeddingDimension = $this->embeddingClient->getEmbeddingDimension();
    }

    /**
     * @param string[]                             $pathsFilter
     * @param string[]                             $excludedDirs
     * @param string[]                             $excludedNamePatterns
     * @param null|callable(int $total): void      $onStart
     * @param null|callable(IndexedFileResult,int,int): void $onFileProcessed
     */
    public function indexDirectory(
        string $rootDir,
        bool $forceReindex = false,
        bool $dryRun = false,
        bool $testMode = false,
        bool $offlineFallback = false,
        array $pathsFilter = [],
        array $excludedDirs = [],
        array $excludedNamePatterns = [],
        ?callable $onStart = null,
        ?callable $onFileProcessed = null,
    ): IndexSummary {
        $files           = [];
        $totalFilesFound = 0;
        $totalProcessed  = 0;
        $totalIndexed    = 0;
        $totalSkipped    = 0;
        $totalFailed     = 0;

        // 1) Prima passata: individua i file candidati
        $candidateFiles = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $totalFilesFound++;

            $absolutePath = $fileInfo->getPathname();
            $relativePath = $this->makeRelativePath($rootDir, $absolutePath);
            $extension    = strtolower($fileInfo->getExtension());

            if ($this->isInExcludedDir($relativePath, $excludedDirs)
                || $this->isExcludedName($fileInfo->getFilename(), $excludedNamePatterns)
                || !$this->matchesPathsFilter($relativePath, $pathsFilter)
            ) {
                $files[] = new IndexedFileResult(
                    $absolutePath,
                    $relativePath,
                    $extension !== '' ? $extension : null,
                    FileIndexStatus::SKIPPED_EXCLUDED,
                    wasReindexed: false,
                    wasNew: false,
                    chunksCount: 0,
                );
                $totalSkipped++;
                continue;
            }

            $candidateFiles[] = [
                'absolutePath' => $absolutePath,
                'relativePath' => $relativePath,
                'extension'    => $extension !== '' ? $extension : null,
            ];
        }

        $totalToProcess = \count($candidateFiles);

        // Notifica al chiamante il totale (per inizializzare la progress bar)
        if ($onStart !== null) {
            $onStart($totalToProcess);
        }

        // 2) Seconda passata: indicizza i file candidati
        $current = 0;

        foreach ($candidateFiles as $fileData) {
            $current++;

            $result = $this->indexSingleFile(
                $fileData['absolutePath'],
                $fileData['relativePath'],
                $fileData['extension'],
                $forceReindex,
                $dryRun,
                $testMode,
                $offlineFallback,
            );

            $files[] = $result;
            $totalProcessed++;

            match ($result->status) {
                FileIndexStatus::INDEXED_OK,
                FileIndexStatus::INDEXED_WITH_ERRORS => $totalIndexed++,
                FileIndexStatus::SKIPPED_UNCHANGED   => $totalSkipped++,
                FileIndexStatus::SKIPPED_EXCLUDED    => $totalSkipped++,
                FileIndexStatus::FAILED              => $totalFailed++,
            };

            if ($onFileProcessed !== null) {
                $onFileProcessed($result, $current, $totalToProcess);
            }
        }

        return new IndexSummary(
            files: $files,
            totalFilesFound: $totalFilesFound,
            totalProcessed: $totalProcessed,
            totalIndexed: $totalIndexed,
            totalSkipped: $totalSkipped,
            totalFailed: $totalFailed,
            dryRun: $dryRun,
            testMode: $testMode,
        );
    }

    private function indexSingleFile(
        string $absolutePath,
        string $relativePath,
        ?string $extension,
        bool $forceReindex,
        bool $dryRun,
        bool $testMode,
        bool $offlineFallback,
    ): IndexedFileResult {
        $repo = $this->em->getRepository(DocumentFile::class);

        $fileSize = @filesize($absolutePath) ?: 0;
        $fileHash = @hash_file('xxh3', $absolutePath) ?: null;

        /** @var DocumentFile|null $docFile */
        $docFile = $repo->findOneBy(['path' => $relativePath]);

        $wasNew       = false;
        $wasReindexed = false;

        if ($docFile !== null && !$forceReindex && $fileHash !== null && $docFile->getHash() === $fileHash) {
            return new IndexedFileResult(
                $absolutePath,
                $relativePath,
                $extension,
                FileIndexStatus::SKIPPED_UNCHANGED,
                wasReindexed: false,
                wasNew: false,
                chunksCount: $docFile->getChunks()->count(),
            );
        }

        if ($docFile === null) {
            $docFile = new DocumentFile();
            $docFile->setPath($relativePath);
            $wasNew = true;
        } else {
            $wasReindexed = true;
        }

        // Estrzione testo
        try {
            $text = $this->extractor->extract($absolutePath);

            // Formato non supportato o non leggibile: lo segno come skip esplicito
            if ($text === null) {
                return new IndexedFileResult(
                    $absolutePath,
                    $relativePath,
                    $extension,
                    FileIndexStatus::SKIPPED_EXCLUDED,
                    wasReindexed: $wasReindexed,
                    wasNew: $wasNew,
                    chunksCount: 0,
                    errorMessage: 'Formato non supportato o file non leggibile',
                );
            }
        } catch (\Throwable $e) {
            return new IndexedFileResult(
                $absolutePath,
                $relativePath,
                $extension,
                FileIndexStatus::FAILED,
                wasReindexed: $wasReindexed,
                wasNew: $wasNew,
                chunksCount: 0,
                errorMessage: 'Estrazione del testo fallita ' . $e->getMessage(),
            );
        }

        // Chunking
        $chunks      = $this->chunking->chunkText($text);
        $chunksCount = \count($chunks);

        if ($dryRun) {
            return new IndexedFileResult(
                $absolutePath,
                $relativePath,
                $extension,
                FileIndexStatus::INDEXED_OK,
                wasReindexed: $wasReindexed,
                wasNew: $wasNew,
                chunksCount: $chunksCount,
            );
        }

        // Aggiorna metadati file
        if ($fileHash !== null && method_exists($docFile, 'setHash')) {
            $docFile->setHash($fileHash);
        }
        if (method_exists($docFile, 'setSize')) {
            $docFile->setSize($fileSize);
        }
        if (method_exists($docFile, 'setExtension')) {
            $docFile->setExtension($extension);
        }
        if (method_exists($docFile, 'setIndexedAt')) {
            $docFile->setIndexedAt(new \DateTimeImmutable());
        }

        // Cancella chunk esistenti
        $this->em->createQueryBuilder()
            ->delete(DocumentChunk::class, 'c')
            ->where('c.file = :file')
            ->setParameter('file', $docFile)
            ->getQuery()
            ->execute();

        $this->em->persist($docFile);

        $hadErrors = false;

        foreach ($chunks as $index => $chunkText) {
            $isPlaceholder = false;
            if ($testMode) {
                $embedding = $this->normalizeEmbedding(
                    embedding: null,
                    chunkText: $chunkText,
                    hadErrors: $hadErrors,
                    markAsError: false,
                    isPlaceholder: $isPlaceholder
                );
            } else {
                try {
                    $rawEmbedding = $this->embeddingClient->embed($chunkText);
                } catch (\Throwable $e) {
                    if ($offlineFallback) {
                        $rawEmbedding = null;
                        $hadErrors = true;
                    } else {
                        return new IndexedFileResult(
                            $absolutePath,
                            $relativePath,
                            $extension,
                            FileIndexStatus::FAILED,
                            wasReindexed: $wasReindexed,
                            wasNew: $wasNew,
                            chunksCount: $index,
                            errorMessage: 'Embedding fallito: ' . $e->getMessage(),
                        );
                    }
                }

                $embedding = $this->normalizeEmbedding(
                    embedding: $rawEmbedding,
                    chunkText: $chunkText,
                    hadErrors: $hadErrors,
                    markAsError: true,
                    isPlaceholder: $isPlaceholder
                );
            }

            $chunk = new DocumentChunk();
            $chunk->setFile($docFile);
            $chunk->setChunkIndex($index);
            $chunk->setContent($chunkText);
            $chunk->setEmbedding($embedding);
            $chunk->setIsSearchable(!$isPlaceholder);

            $this->em->persist($chunk);
        }

        $this->em->flush();
        $this->em->clear();

        return new IndexedFileResult(
            $absolutePath,
            $relativePath,
            $extension,
            $hadErrors ? FileIndexStatus::INDEXED_WITH_ERRORS : FileIndexStatus::INDEXED_OK,
            wasReindexed: $wasReindexed,
            wasNew: $wasNew,
            chunksCount: $chunksCount,
            errorMessage: $hadErrors ? 'Su alcuni file, per gli embedding è stata usata la modolità offline fallback' : null,
        );
    }

    private function makeRelativePath(string $rootDir, string $absolutePath): string
    {
        $relative = str_replace($rootDir, '', $absolutePath);

        return ltrim(str_replace('\\', '/', $relative), '/');
    }

    /**
     * @param string[] $excludedDirs
     */
    private function isInExcludedDir(string $relativePath, array $excludedDirs): bool
    {
        foreach ($excludedDirs as $dir) {
            $dir = trim($dir, '/');
            if ($dir === '') {
                continue;
            }
            if (str_starts_with($relativePath, $dir . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $patterns
     */
    private function isExcludedName(string $filename, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $filename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $pathsFilter
     */
    private function matchesPathsFilter(string $relativePath, array $pathsFilter): bool
    {
        if ($pathsFilter === []) {
            return true;
        }

        foreach ($pathsFilter as $filter) {
            $filter = trim($filter, '/');
            if ($filter === '') {
                continue;
            }
            if (str_starts_with($relativePath, $filter . '/')
                || $relativePath === $filter
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Garantisce che ogni embedding salvato abbia la dimensione corretta. 
     * Se il backend non restituisce un vettore valido, genera un placeholder 
     * deterministico (che non impatta la ricerca) per mantenere consistente 
     * la colonna pgvector.
     */
    private function normalizeEmbedding(
        ?array $embedding,
        string $chunkText,
        bool &$hadErrors,
        bool $markAsError,
        bool &$isPlaceholder
    ): array {
        // Accetto solo vettori corretti e della dimensione attesa, forzando i valori a float
        if (is_array($embedding) && count($embedding) === $this->embeddingDimension) {
            $isPlaceholder = false;
            
            // Se il client AI è Gemini Normalizzo l'embedding con la norma L2
            if ($this->embeddingClient instanceof GeminiClient) {
                return $this->l2Normalize($embedding);
            } else {
                return array_map(static fn($value) => (float) $value, $embedding);
            }
        }

        if ($markAsError) {
            $hadErrors = true;
        }

        $isPlaceholder = true;

        return $this->fakeEmbeddingFromText($chunkText);
    }

    /**
     * Genera un embedding deterministico (placeholder) con la stessa dimensione del modello configurato.
     */
    private function fakeEmbeddingFromText(string $text): array
    {
        $vector = [];

        for ($i = 0; $i < $this->embeddingDimension; $i++) {
            $seed = $text . '|' . $i;
            $hash = md5($seed);
            $chunk = substr($hash, 0, 8);
            $int = hexdec($chunk);
            $normalized = ($int / self::HASH_NORMALIZER) * 2 - 1;
            $vector[] = (float) $normalized;
        }

        return $vector;
    }

    /**
     * In caso di Backend Gemini
     * Per utilizzare il modello di embedding gemini-embedding-001(Consigliato da docs Gemini)
     * 
     * Il modello restituisce vettori già normalizzati a 3072 dim, ma per dimensioni più piccole
     * 1536, 768 ecc. ecc. devono essere normalizzati tramite la norma L2
     * 
     * La funzione calcola la norma L2 di un vettore (somma dei quadrati e sqrt)
     * per poi usarla nella normalizzazione dei valori, così gli embedding vengono 
     * ridimensionati mantenendo la direzione ma con lunghezza unitaria.
     * 
     * La norma L2 è la misura della lunghezza di un vettore nello spazio euclideo:
     * somma i quadrati delle componenti, fa la radice quadrata (sqrt(x1² + x2² + … + xn²)).
     * Normalizzare con la L2 porta il vettore ad avere lunghezza 1 mantenendo la stessa direzione.
     */
    function l2Normalize(array $embedding): array
    {
        // somma dei quadrati
        $sumSquares = 0.0;
        foreach ($embedding as $v) {
            $sumSquares += ((float)$v) ** 2;
        }

        $norm = sqrt($sumSquares);

        // evita divisioni per zero
        if ($norm == 0.0) {
            return $embedding;
        }

        return array_map(
            static fn($v) => (float)$v / $norm,
            $embedding
        );
    }
}
