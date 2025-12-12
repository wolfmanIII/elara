<?php

namespace App\AI;

use OpenAI;

class OpenAiClient implements AiClientInterface
{
    public function __construct(
        private string $apiKey,
        private string $chatModel = 'gpt-4.1-mini',
        private string $embedModel = 'text-embedding-3-small',
        private int $dimension,
    ) {}

    public function embed(string $text): ?array
    {
        $client = OpenAI::client($this->apiKey);

        $resp = $client->embeddings()->create([
            'model' => $this->embedModel,
            'input' => $text,
            'dimension' => $this->dimension,
        ]);

        return $resp->embeddings[0]->embedding;
    }

    public function chat(string $question, string $context, ?string $source): string
    {
        $system = <<<TXT
Sei un assistente e DEVI rispondere esclusivamente usando il contesto sotto.
Se la risposta non è presente nel contesto, di' che non è disponibile.
TXT;

        $user = <<<TXT
CONTESTO:
$context

DOMANDA:
$question

Rispondi in modo chiaro e nella lingua dell'utente.
TXT;

        $client = OpenAI::client($this->apiKey);

        $resp = $client->chat()->create([
            'model' => $this->chatModel,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'max_tokens' => 400,
        ]);

        //return $resp->choices[0]->message->content ?? '';
        return $resp->choices[0]->message->content . "\n\n" . $source ?? '';
    }

    public function getEmbeddingDimension(): int
    {
        return $this->dimension;
    }
}
