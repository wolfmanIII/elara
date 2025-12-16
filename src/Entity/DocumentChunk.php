<?php

namespace App\Entity;

use App\Repository\DocumentChunkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DocumentChunkRepository::class)]
#[ORM\Table(name: 'document_chunk', indexes: [
    new ORM\Index(name: 'document_chunk_embedding_hnsw', columns: ['embedding']),
])]
class DocumentChunk
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(inversedBy: 'chunks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DocumentFile $file = null;

    #[ORM\Column]
    private int $chunkIndex;

    #[ORM\Column(type: 'text')]
    private string $content;

    // colonna pgvector(1024)
    #[ORM\Column(type: 'vector', length: 1024)]
    private array $embedding = [];

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $searchable = true;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getFile(): ?DocumentFile
    {
        return $this->file;
    }

    public function setFile(?DocumentFile $file): self
    {
        $this->file = $file;
        return $this;
    }

    public function getChunkIndex(): int
    {
        return $this->chunkIndex;
    }

    public function setChunkIndex(int $chunkIndex): self
    {
        $this->chunkIndex = $chunkIndex;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getEmbedding(): array
    {
        return $this->embedding;
    }

    public function setEmbedding(array $embedding): self
    {
        $this->embedding = $embedding;
        return $this;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function setIsSearchable(bool $searchable): self
    {
        $this->searchable = $searchable;
        return $this;
    }
}
