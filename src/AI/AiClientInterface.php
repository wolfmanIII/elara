<?php

namespace App\AI;

interface AiClientInterface
{
    /**
     * Genera embedding per il testo (restituisce un array di float).
     */
    public function embed(string $text): array;

    /**
     * Risponde alla domanda usando un certo contesto.
     */
    public function chat(string $question, string $context): string;
}
