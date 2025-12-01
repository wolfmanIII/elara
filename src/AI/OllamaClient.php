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
        private string              $chatModel = 'llama3.2',
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
        $response = $this->httpClient->request(
            'POST',
            $this->host.'/api/embed',
            [
                'json' => [
                    'model' => $this->embedModel,
                    'input' => $text,
                ]
            ]
        );

        $data = $response->toArray();

        return $data['embeddings'][0] ?? [];
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
CONTESTO:
$context

DOMANDA:
$question

Rispondi SOLO usando il contesto sopra. Se non trovi la risposta, dì che non è presente nei documenti.
TXT;

        $response = $this->httpClient->request(
            'POST',
            $this->host.'/api/generate',
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
