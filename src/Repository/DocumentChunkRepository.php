<?php

namespace App\Repository;

use App\Entity\DocumentChunk;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception;
use Doctrine\Persistence\ManagerRegistry;
use PDO;

class DocumentChunkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentChunk::class);
    }

    /**
     * Trova i chunk più simili a un embedding usando l'operatore vettoriale <=>.
     *
     * Ritorna un array di righe associative:
     * [
     *   [
     *     'chunk_id'    => 123,
     *     'file_path'   => 'manuali/nemici_trast.md',
     *     'chunk_index' => 3,
     *     'content'     => 'Testo del chunk...',
     *     'distance'    => 0.12,
     *   ],
     *   ...
     * ]
     *
     * @throws Exception
     */
    public function findTopKSimilar(array $embedding, int $k = 5): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // SQL nativo: uso l'operatore <=> su embedding
        // NOTA: <=> restituisce la *distanza* (coseno), quindi ordiniamo ASC (più piccolo = più simile)
        $sql = <<<SQL
SELECT
    c.id        AS chunk_id,
    f.path      AS file_path,
    c.chunk_index,
    c.content,
    (c.embedding <=> :query_vec) AS distance
FROM document_chunk c
JOIN document_file f ON c.file_id = f.id
ORDER BY c.embedding <=> :query_vec ASC
LIMIT :k
SQL;

        // L'estensione pgvector accetta il vettore come array PostgreSQL o come testo.
        // Doctrine, se ha il tipo "vector" configurato, converte l'array PHP in formato giusto.
        $stmt = $conn->prepare($sql);

        $stmt->bindValue('query_vec', $embedding);   // vettore della domanda
        $stmt->bindValue('k', $k, PDO::PARAM_INT); // limite risultati

        $result = $stmt->executeQuery();

        return $result->fetchAllAssociative();
    }

    /**
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
            ->select('c', 'f')
            ->join('c.file', 'f')
            ->where('c.embedding IS NOT NULL')
            ->orderBy('cosine_similarity(c.embedding, :vec)', 'DESC')
            ->setMaxResults(5)
            ->setParameter('vec', $embedding)
            ->getQuery()->getResult();
    }
}
