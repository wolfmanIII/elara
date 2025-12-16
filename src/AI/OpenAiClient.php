<?php

namespace App\AI;

use OpenAI;

class OpenAiClient implements AiClientInterface
{
    public function __construct(
        private string $apiKey,
        private string $chatModel,
        private string $embedModel,
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
            'max_tokens' => 1024,
        ]);

        //return $resp->choices[0]->message->content ?? '';
        return $resp->choices[0]->message->content . "\n\n" . $source ?? '';
    }

    public function chatStream(
        string $question,
        string $context,
        ?string $source,
        callable $onChunk
    ): void {
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

        $stream = $client->chat()->createStreamed([
            'model' => $this->chatModel,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'max_tokens' => 1024,
        ]);

        foreach ($stream as $response) {
            foreach ($response->choices as $choice) {
                $deltaContentList = $choice->delta?->content ?? null;
                if (!is_array($deltaContentList)) {
                    continue;
                }

                foreach ($deltaContentList as $deltaContent) {
                    $text = $deltaContent->text ?? null;
                    if ($text !== null && $text !== '') {
                        $onChunk($text);
                    }
                }
            }
        }

        if ($source !== null) {
            $onChunk("\n\n" . $source);
        }
    }

    public function getEmbeddingDimension(): int
    {
        return $this->dimension;
    }
}
