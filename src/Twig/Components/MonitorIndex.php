<?php

namespace App\Twig\Components;

use App\Repository\DocumentChunkRepository;
use App\Repository\DocumentFileRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class MonitorIndex
{
    use DefaultActionTrait;

    public function __construct(
        private readonly DocumentFileRepository $fileRepository,
        private readonly DocumentChunkRepository $chunkRepository,
        private readonly CacheInterface $cache,
    ) {}

    public function getStats(): array
    {
        return $this->cache->get('monitor_index_stats', function (ItemInterface $item) {
            $item->expiresAfter(30);
            return $this->buildStats();
        });
    }

    private function buildStats(): array
    {
        $totalFiles = $this->fileRepository->count([]);
        $totalChunks = $this->chunkRepository->count([]);
        $totalSize = $this->sumFilesSize();
        $lastIndexed = $this->fileRepository->findOneBy([], ['indexedAt' => 'DESC']);
        $nonSearchableChunks = $this->countNonSearchableChunks();
        $placeholderRatio = $totalChunks > 0
            ? ($nonSearchableChunks / $totalChunks) * 100
            : 0.0;

        return [
            'total_files' => $totalFiles,
            'total_chunks' => $totalChunks,
            'total_size' => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize),
            'last_indexed_at' => $lastIndexed?->getIndexedAt(),
            'last_indexed_path' => $lastIndexed?->getPath(),
            'non_searchable_chunks' => $nonSearchableChunks,
            'placeholder_ratio' => $placeholderRatio,
        ];
    }

    private function sumFilesSize(): int
    {
        return (int) $this->fileRepository->createQueryBuilder('f')
            ->select('COALESCE(SUM(f.size), 0)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return sprintf('%.1f %s', $value, $units[$power]);
    }

    private function countNonSearchableChunks(): int
    {
        return (int) $this->chunkRepository->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.searchable = :searchable')
            ->setParameter('searchable', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    #[LiveAction]
    public function interval(): void
    {
        // azione invocata dal polling, non serve logica: il componente verrà ricalcolato
    }
}
