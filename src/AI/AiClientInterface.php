<?php

namespace App\AI;

interface AiClientInterface
{
    /**
     * Genera embedding per il testo (restituisce un array di float).
     */
    public function embed(string $text): ?array;

    /**
     * Risponde alla domanda usando un certo contesto.
     */
    public function chat(string $question, string $context, ?string $source): string;

    /**
     * Variante streaming: invoca $onChunk per ogni porzione di risposta ricevuta dal modello.
     *
     * @param callable(string $chunk): void $onChunk
     */
    public function chatStream(
        string $question,
        string $context,
        ?string $source,
        callable $onChunk
    ): void;

    /**
     * Restituisce la dimensionalità degli embedding generati dal client.
     */
    public function getEmbeddingDimension(): int;
}
