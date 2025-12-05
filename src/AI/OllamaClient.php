<?php

namespace App\AI;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OllamaClient implements AiClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string              $host = 'http://localhost:11434',
        private string              $embedModel = 'nomic-embed-text',
        private string              $chatModel = 'llama3.1:8b',
    ) {}

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function embed(string $text): array
    {
        // Ollama /api/embed si aspetta SEMPRE un array di stringhe in "input"
        $response = $this->httpClient->request(
            'POST',
            rtrim($this->host, '/') . '/api/embed',
            [
                'json' => [
                    'model' => $this->embedModel,   // es. 'nomic-embed-text'
                    'input' => [$text],             // singolo testo -> array con 1 elemento
                ],
            ]
        );

        $data = $response->toArray(false);

        // Verifica robusta: ci deve essere almeno embeddings[0]
        if (
            !isset($data['embeddings'][0])
            || !is_array($data['embeddings'][0])
            || count($data['embeddings'][0]) === 0
        ) {
            throw new \RuntimeException(
                'Embedding mancante o vuoto dalla API Ollama per il testo: ' .
                mb_substr($text, 0, 80)
            );
        }

        // Ritorno il vettore singolo (array<float>, dim ~768)
        return $data['embeddings'][0];
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function chat(string $question, string $context): string
    {
        $prompt = <<<TXT
Sei un assistente e DEVI rispondere esclusivamente usando il contesto sotto.
Se la risposta non è presente nel contesto, di' che non è disponibile.

CONTESTO:
$context

DOMANDA:
$question

Rispondi in modo chiaro nella lingua dell'utente.
TXT;

        $response = $this->httpClient->request(
            'POST',
            $this->host . '/api/generate',
            [
                'json' => [
                    'model' => $this->chatModel,
                    'prompt' => $prompt,
                    'stream' => false,
                ]
            ]
        );

        $data = $response->toArray();

        return $data['response'] ?? '';
    }
}
