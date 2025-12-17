<?php

declare(strict_types=1);

namespace App\Rag;

use App\Entity\DocumentChunk;
use Doctrine\ORM\EntityManagerInterface;

final class EmbeddingSchemaInspector
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function getSchemaDimension(): ?int
    {
        $metadata = $this->em->getClassMetadata(DocumentChunk::class);

        if (!$metadata->hasField('embedding')) {
            return null;
        }

        $mapping = $metadata->getFieldMapping('embedding');

        return isset($mapping['length']) ? (int) $mapping['length'] : null;
    }

    public function isAlignedWithProfile(array $profile): bool
    {
        $schemaDimension  = $this->getSchemaDimension();
        $profileDimension = (int) ($profile['ai']['embed_dimension'] ?? 0);

        if ($schemaDimension === null || $profileDimension <= 0) {
            return true;
        }

        return $schemaDimension === $profileDimension;
    }
}
