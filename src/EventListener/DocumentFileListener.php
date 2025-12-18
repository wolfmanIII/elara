<?php

namespace App\EventListener;

use App\Entity\DocumentFile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class DocumentFileListener
{
    public function __construct(private Security $security)
    {
    }

    public function prePersist(PrePersistEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof DocumentFile) {
            return;
        }

        $this->applyAuditInfo($entity, assignUser: true);
    }

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof DocumentFile) {
            return;
        }

        $this->applyAuditInfo($entity, assignUser: false);
    }

    private function applyAuditInfo(DocumentFile $entity, bool $assignUser): void
    {
        if ($assignUser) {
            $user = $this->security->getUser();
            if ($user instanceof User) {
                $entity->setIndexedBy($user);
            }
        }

        $entity->setIndexedAt(new \DateTimeImmutable());
    }
}
