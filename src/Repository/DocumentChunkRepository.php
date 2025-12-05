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
     * vecchia estrazione non molto ottimizzata
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
    public function findTopSimilarByCosineSimilarityOld(array $embedding, int $k = 5): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.id', 'c.content as chunk_content', 'c.chunkIndex as chunk_index', 'f.path as file_path')
            ->join('c.file', 'f')
            ->where('c.embedding IS NOT NULL')
            ->orderBy('cosine_similarity(c.embedding, :vec)', 'DESC')
            ->setMaxResults(5)
            ->setParameter('vec', $embedding, 'vector')
            ->getQuery()->getResult();
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
    public function findTopKCosineSimilarity(array $embedding, int $k = 5): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.id')
            ->addSelect('c.content AS chunk_content')
            ->addSelect('c.chunkIndex AS chunk_index')
            ->addSelect('f.path AS file_path')
            ->addSelect('cosine_similarity(c.embedding, :vec) AS similarity')
            ->join('c.file', 'f')
            ->where('c.embedding IS NOT NULL')
            ->andWhere('cosine_similarity(c.embedding, :vec) > :minScore')
            ->orderBy('similarity', 'DESC')
            ->setMaxResults($k)
            ->setParameter('vec', $embedding, 'vector')
            ->setParameter('minScore', 0.55)
            ->getQuery()
            ->getResult();
    }

}
