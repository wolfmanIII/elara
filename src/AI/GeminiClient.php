<?php

namespace App\AI;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiClient implements AiClientInterface
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
        private string $chatModel = 'gemini-1.5-flash',
        private string $embedModel = 'text-embedding-004',
        private int $dimension = 768,
    ) {}

    public function embed(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $response = $this->httpClient->request(
            'POST',
            sprintf('%s/models/%s:embedContent?key=%s', self::BASE_URL, $this->embedModel, $this->apiKey),
            [
                'json' => [
                    'model' => $this->embedModel,
                    'content' => [
                        'parts' => [
                            ['text' => $text],
                        ],
                    ],
                    'outputDimensionality' => $this->dimension,
                ],
                'timeout' => 120,
            ],
        );

        $data = $response->toArray(false);

        if (
            !isset($data['embedding']['values'])
            || !is_array($data['embedding']['values'])
        ) {
            throw new \RuntimeException('Risposta embedContent inattesa da Gemini.');
        }

        $vector = array_map(static fn($value) => (float) $value, $data['embedding']['values']);

        if (count($vector) !== $this->dimension) {
            throw new \RuntimeException(sprintf(
                'Dimensione embedding errata: atteso %d, ottenuto %d',
                $this->dimension,
                count($vector)
            ));
        }

        return $vector;
    }

    public function chat(string $question, string $context): string
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

        $response = $this->httpClient->request(
            'POST',
            sprintf('%s/models/%s:generateContent?key=%s', self::BASE_URL, $this->chatModel, $this->apiKey),
            [
                'json' => [
                    'system_instruction' => [
                        'parts' => [
                            ['text' => $system],
                        ],
                    ],
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => $user],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => 400,
                    ],
                ],
                'timeout' => 120,
            ],
        );

        $data = $response->toArray(false);

        $firstCandidate = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return is_string($firstCandidate) ? $firstCandidate : '';
    }

    public function getEmbeddingDimension(): int
    {
        return $this->dimension;
    }
}
