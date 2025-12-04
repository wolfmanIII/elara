<?php

namespace App\Repository;

use App\Entity\DocumentChunk;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception;
use Doctrine\Persistence\ManagerRegistry;
use Partitech\DoctrinePgVector\Type\VectorType;
use PDO;
use Doctrine\DBAL\Types\Type;

class DocumentChunkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentChunk::class);
    }

    /**
     * Trova i chunk più simili a un embedding
     * 
     * Non avendo a disposizione l'operatore vettoriale <=>
     * utilizzo l'estensione cosine_similarity di pgvector per Postgres
     *
     * @param array $embedding
     * @param int $k
     * @return array
     */
    public function findTopSimilarByCosineSimilarity(array $embedding, int $k = 5): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.id, c.content as chunk_content', 'c.chunkIndex as chunk_index', 'f.path as file_path')
            ->join('c.file', 'f')
            ->where('c.embedding IS NOT NULL')
            ->orderBy('cosine_similarity(c.embedding, :vec)', 'DESC')
            ->setMaxResults(20)
            ->setParameter('vec', $embedding, 'vector')
            ->getQuery()->getResult();
    }
}
