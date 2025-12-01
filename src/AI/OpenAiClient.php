<?php

namespace App\AI;

use OpenAI;

class OpenAiClient implements AiClientInterface
{
    public function __construct(
        private string $apiKey,
        private string $chatModel = 'gpt-5.1-mini',
        private string $embedModel = 'text-embedding-3-small',
    ) {}

    public function embed(string $text): array
    {
        $client = OpenAI::client($this->apiKey);

        $resp = $client->embeddings()->create([
            'model' => $this->embedModel,
            'input' => $text,
        ]);

        return $resp->embeddings[0]->embedding;
    }

    public function chat(string $question, string $context): string
    {
        $system = <<<TXT
Rispondi solo basandoti sul contesto fornito.
TXT;

        $user = <<<TXT
CONTESTO:
$context

DOMANDA:
$question
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

        return $resp->choices[0]->message->content ?? '';
    }
}
