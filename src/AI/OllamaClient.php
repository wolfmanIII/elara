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

    private const DEFAULT_BATCH_MAX = 4;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string              $host,
        private string              $embedModel,
        private string              $chatModel,
        private string              $chatModelDeep,
        private string              $dimension
    ) {
        $this->host = $_ENV["OLLAMA_HOST"];
        $this->embedModel = $_ENV["OLLAMA_EMBED_MODEL"];
        $this->chatModel = $_ENV["OLLAMA_CHAT_MODEL"];
        $this->chatModelDeep = $_ENV["OLLAMA_CHAT_MODEL_DEEP"];
        $this->dimension = $_ENV["OLLAMA_EMBED_DIMENSION"];
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
     * Embedding di PIÙ testi in una volta, con mini-batching.
     * Da verificare se è davvero utile **
     *
     * @param string[] $texts
     * @return array<int, float[]|null>  Stesso ordine di $texts
     */
    public function embedMany(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $results = [];

        // Spezza in mini-batch per non stressare /api/embed
        foreach (array_chunk($texts, self::DEFAULT_BATCH_MAX) as $group) {
            // Prepara input e mappa per tenere traccia degli elementi vuoti
            $payloadInputs = [];
            $indexMap      = []; // indice locale batch -> indice globale $texts

            foreach ($group as $i => $text) {
                $globalIndex = count($results) + $i;

                $text = $text === null ? '' : trim((string) $text);

                if ($text === '') {
                    // per ora segniamo null; manterremo l’ordine dopo
                    $results[$globalIndex] = null;
                    continue;
                }

                $payloadInputs[]                  = $text;
                $indexMap[count($payloadInputs)-1] = $globalIndex;
            }

            if ($payloadInputs === []) {
                // Tutto vuoto in questo batch
                continue;
            }

            $response = $this->httpClient->request(
                'POST',
                rtrim($this->host, '/') . '/api/embed',
                [
                    'json' => [
                        'model' => $this->embedModel,
                        'input' => $payloadInputs,  // array di stringhe
                    ],
                    'timeout' => 120,
                ]
            );

            $data = $response->toArray(false);

            if (!isset($data['embeddings']) || !is_array($data['embeddings'])) {
                throw new \RuntimeException('Risposta /api/embed inattesa da Ollama (manca embeddings).');
            }

            foreach ($data['embeddings'] as $localIdx => $vector) {
                if (!array_key_exists($localIdx, $indexMap)) {
                    continue;
                }

                $globalIndex = $indexMap[$localIdx];

                if (!is_array($vector)) {
                    throw new \RuntimeException('Embedding non è un array.');
                }

                $vector = array_map(static fn($v) => (float) $v, $vector);

                $count = count($vector);
                if ($count !== $this->dimension) {
                    throw new \RuntimeException(sprintf(
                        'Dimensione embedding errata: atteso %d, ottenuto %d (indice %d)',
                        $this->dimension,
                        $count,
                        $globalIndex
                    ));
                }

                $results[$globalIndex] = $vector;
            }
        }

        // Normalizza: riordina per indice e rimuove gap
        ksort($results);

        // Se per qualche motivo non abbiamo popolato tutto, completa con null
        if (count($results) < count($texts)) {
            for ($i = 0; $i < count($texts); $i++) {
                if (!array_key_exists($i, $results)) {
                    $results[$i] = null;
                }
            }
            ksort($results);
        }

        return array_values($results);
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

        //return $data['response'] . "\n\n" . $context ?? '';
        return $data['response'] ?? '';
    }
}
