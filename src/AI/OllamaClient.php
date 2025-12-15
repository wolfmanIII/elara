<?php

namespace App\AI;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Source;

class OllamaClient implements AiClientInterface
{

    private const DEFAULT_BATCH_MAX = 4;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string              $host,
        private string              $embedModel,
        private string              $chatModel,
        private int                 $dimension
    ) {
    }

    /**
     * Embedding di UNA singola stringa.
     *
     * @return float[]|null  1024 float, oppure null se testo vuoto/null
     */
    public function embed(?string $text): ?array
    {
        $text = $text === null ? '' : trim($text);

        if ($text === '') {
            return null;
        }

        $response = $this->httpClient->request(
            'POST',
            rtrim($this->host, '/') . '/api/embed',
            [
                'json' => [
                    'model' => $this->embedModel,
                    'input' => [$text],   // singola stringa
                ],
                'timeout' => 120,
            ]
        );

        $data = $response->toArray(false);

        if (!isset($data['embeddings']) || !is_array($data['embeddings']) || !isset($data['embeddings'][0])) {
            throw new \RuntimeException('Risposta /api/embed inattesa da Ollama (manca embeddings[0]).');
        }

        $vector = $data['embeddings'][0];

        if (!is_array($vector)) {
            throw new \RuntimeException('Embedding non è un array.');
        }

        // Cast a float e verifica dimensione
        $vector = array_map(static fn($v) => (float) $v, $vector);

        $count = count($vector);
        if ($count !== (int)$this->dimension) {
            throw new \RuntimeException(sprintf(
                'Dimensione embedding errata: atteso %d, ottenuto %d',
                $this->dimension,
                $count
            ));
        }

        return $vector;
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function chat(string $question, string $context, ?string $source): string
    {
        $prompt = <<<TXT
Sei un assistente e DEVI rispondere esclusivamente usando il contesto sotto.
Se la risposta non è presente nel contesto, di' che non è disponibile.

CONTESTO:
$context

DOMANDA:
$question

Rispondi in modo chiaro e nella lingua dell'utente.
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

       
        return $data['response'] . "\n\n" . $source ?? ''; 
        //return $data['response'] ?? '';
    }

    public function chatStream(
        string $question,
        string $context,
        ?string $source,
        callable $onChunk
    ): void {
        $prompt = <<<TXT
Sei un assistente e DEVI rispondere esclusivamente usando il contesto sotto.
Se la risposta non è presente nel contesto, di' che non è disponibile.

CONTESTO:
$context

DOMANDA:
$question

Rispondi in modo chiaro e nella lingua dell'utente.
TXT;

        $response = $this->httpClient->request(
            'POST',
            $this->host . '/api/generate',
            [
                'json' => [
                    'model'  => $this->chatModel,
                    'prompt' => $prompt,
                    'stream' => true,
                ],
                'timeout' => 15,
            ]
        );

        $buffer = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                continue;
            }

            $buffer .= $chunk->getContent();

            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePos));
                $buffer = substr($buffer, $newlinePos + 1);

                if ($line === '') {
                    continue;
                }

                $payload = json_decode($line, true);
                if (!is_array($payload)) {
                    continue;
                }

                if (isset($payload['response']) && is_string($payload['response']) && $payload['response'] !== '') {
                    $onChunk($payload['response']);
                }

                if (($payload['done'] ?? false) === true) {
                    if ($source !== null) {
                        $onChunk("\n\n" . $source);
                    }
                    return;
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
