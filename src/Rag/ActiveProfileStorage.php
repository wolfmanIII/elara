<?php

declare(strict_types=1);

namespace App\Rag;

final class ActiveProfileStorage
{
    public function __construct(
        private readonly string $storagePath
    ) {}

    public function load(): ?string
    {
        if (!is_file($this->storagePath)) {
            return null;
        }

        $content = @file_get_contents($this->storagePath);
        if ($content === false) {
            return null;
        }

        $value = trim($content);

        return $value !== '' ? $value : null;
    }

    public function save(string $profileName): void
    {
        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Impossibile creare la cartella profili in %s', $dir));
            }
        }

        if (@file_put_contents($this->storagePath, $profileName) === false) {
            throw new \RuntimeException(sprintf(
                'Impossibile salvare il profilo attivo in %s',
                $this->storagePath
            ));
        }
    }
}
