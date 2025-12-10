<?php

namespace App\Model\Index;

final class IndexedFileResult
{
    public function __construct(
        public readonly string $absolutePath,
        public readonly string $relativePath,
        public readonly ?string $extension,
        public readonly FileIndexStatus $status,
        public readonly bool $wasReindexed,
        public readonly bool $wasNew,
        public readonly int $chunksCount,
        public readonly ?string $errorMessage = null,
    ) {}
}
