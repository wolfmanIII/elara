<?php

namespace App\EventListener;

use App\Entity\DocumentFile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Symfony\Bundle\SecurityBundle\Security;

#[AsDoctrineListener(event: Events::prePersist)]
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

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $entity->setIndexedBy($user);
        }
    }
}
