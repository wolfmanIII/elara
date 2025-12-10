<?php

namespace App\Repository;

use App\Entity\DocumentChunk;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DocumentChunkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentChunk::class);
    }

    /**
     * Query ottimizzata
     * 
     * Trova i chunk più simili a un embedding
     * 
     * Non avendo a disposizione l'operatore vettoriale <=>
     * utilizzo l'estensione cosine_similarity di pgvector per Postgres
     *
     * @param array $embedding
     * @param int $k
     * @return array
     */
    public function findTopKCosineSimilarity(array $embedding, int $k = 4): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.id')
            ->addSelect('c.content AS chunk_content')
            ->addSelect('c.chunkIndex AS chunk_index')
            ->addSelect('f.path AS file_path')
            ->addSelect('cosine_similarity(c.embedding, :vec) AS similarity')
            ->join('c.file', 'f')
            ->where('c.embedding IS NOT NULL')
            ->andWhere('c.searchable = true')
            ->andWhere('cosine_similarity(c.embedding, :vec) > :minScore')
            ->orderBy('similarity', 'DESC')
            ->setMaxResults($k)
            ->setParameter('vec', $embedding, 'vector')
            ->setParameter('minScore', 0.55)
            ->getQuery()
            ->getResult();
    }

}
