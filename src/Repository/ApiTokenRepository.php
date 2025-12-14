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
        $apiToken = $this->findOneBy(['token' => $token]);
        if (!$apiToken) {
            return null;
        }

        return $apiToken->isExpired() ? null : $apiToken;
    }
}
