<?php

namespace App\Model\Index;

final class IndexSummary
{
    /**
     * @param IndexedFileResult[] $files
     */
    public function __construct(
        public readonly array $files,
        public readonly int $totalFilesFound,
        public readonly int $totalProcessed,
        public readonly int $totalIndexed,
        public readonly int $totalSkipped,
        public readonly int $totalFailed,
        public readonly bool $dryRun,
        public readonly bool $testMode,
    ) {}
}
