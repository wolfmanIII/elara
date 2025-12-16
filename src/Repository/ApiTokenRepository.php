<?php

namespace App\Repository;

use App\Entity\ApiToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findValidToken(string $token): ?ApiToken
    {
        $hash = hash('sha256', $token);

        // Preferisce match su hash; fallback per eventuali token legacy in chiaro
        $apiToken = $this->findOneBy(['token' => $hash]);
        if (!$apiToken) {
            $apiToken = $this->findOneBy(['token' => $token]);
        }

        if (!$apiToken || $apiToken->isExpired() || $apiToken->isRevoked()) {
            return null;
        }

        return $apiToken;
    }

    /**
     * @return ApiToken[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.user', 'u')->addSelect('u')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.expiresAt > :now')
            ->andWhere('t.revokedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }
}
