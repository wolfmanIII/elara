<?php

declare(strict_types=1);

namespace App\Rag;

final class RagProfileManager
{
    private array $presets;
    private string $activeProfileName;

    public function __construct(
        array $config,
        ?string $envProfile = null,
    ) {
        $this->presets = $config['presets'] ?? [];

        if ($this->presets === []) {
            throw new \RuntimeException('Nessun profilo RAG configurato. Aggiungi almeno un preset in config/packages/rag_profiles.yaml');
        }

        $defaultProfile = $config['default_profile'] ?? array_key_first($this->presets);
        $this->activeProfileName = $this->resolveProfileName($envProfile, $defaultProfile);
    }

    public function getActiveProfileName(): string
    {
        return $this->activeProfileName;
    }

    public function getActiveProfile(): array
    {
        return $this->getProfileData($this->activeProfileName);
    }

    public function getChunking(?string $profile = null): array
    {
        return $this->getSection('chunking', $profile);
    }

    public function getRetrieval(?string $profile = null): array
    {
        return $this->getSection('retrieval', $profile);
    }

    public function getAi(?string $profile = null): array
    {
        return $this->getSection('ai', $profile);
    }

    public function listProfiles(): array
    {
        $list = [];

        foreach ($this->presets as $name => $data) {
            $list[] = [
                'name'   => $name,
                'label'  => $data['label'] ?? $name,
                'backend'=> $data['backend'] ?? null,
            ];
        }

        return $list;
    }

    public function useProfile(string $profileName): void
    {
        $this->activeProfileName = $this->resolveProfileName($profileName, $this->activeProfileName);
    }

    public function hasProfile(string $profileName): bool
    {
        return isset($this->presets[$profileName]);
    }

    public function getProfileData(string $profileName): array
    {
        if (!$this->hasProfile($profileName)) {
            throw new \InvalidArgumentException(sprintf(
                'Profilo RAG "%s" non configurato. Disponibili: %s',
                $profileName,
                implode(', ', array_keys($this->presets))
            ));
        }

        return $this->presets[$profileName];
    }

    private function getSection(string $section, ?string $profile = null): array
    {
        $profileName = $profile ?? $this->activeProfileName;
        $data        = $this->getProfileData($profileName);

        if (!isset($data[$section]) || !is_array($data[$section])) {
            throw new \RuntimeException(sprintf(
                'La sezione "%s" non esiste nel profilo RAG "%s".',
                $section,
                $profileName
            ));
        }

        return $data[$section];
    }

    private function resolveProfileName(?string $requested, ?string $fallback): string
    {
        if ($requested !== null && $requested !== '') {
            if (!$this->hasProfile($requested)) {
                throw new \InvalidArgumentException(sprintf(
                    'Profilo RAG "%s" non trovato. Preset disponibili: %s',
                    $requested,
                    implode(', ', array_keys($this->presets))
                ));
            }

            return $requested;
        }

        if ($fallback !== null && $this->hasProfile($fallback)) {
            return $fallback;
        }

        /** @var string $first */
        $first = array_key_first($this->presets);

        return $first;
    }
}
