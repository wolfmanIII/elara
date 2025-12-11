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

        // Normalizzo il vettore con la norma L2
        $vector = $this->l2Normalize($data['embedding']['values']);

        //$vector = array_map(static fn($value) => (float) $value, $data['embedding']['values']);

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

    /**
     * Per utilizzare il modello di embedding gemini-embedding-001(Consigliato da docs Gemini)
     * 
     * Il modello restituisce vettori già normalizzati a 3072 dim, ma per dimensioni più piccole
     * 1536, 768 ecc. ecc. devono essere normalizzati tramite la norma L2
     * 
     * La funzione calcola la norma L2 di un vettore (somma dei quadrati e sqrt)
     * per poi usarla nella normalizzazione dei valori, così gli embedding vengono 
     * ridimensionati mantenendo la direzione ma con lunghezza unitaria.
     * 
     * La norma L2 è la misura della lunghezza di un vettore nello spazio euclideo:
     * somma i quadrati delle componenti, fa la radice quadrata (sqrt(x1² + x2² + … + xn²)).
     * Normalizzare con la L2 porta il vettore ad avere lunghezza 1 mantenendo la stessa direzione.
     */
    private function l2Normalize(array $embedding): array
    {
        // somma dei quadrati
        $sumSquares = 0.0;
        foreach ($embedding as $v) {
            $sumSquares += ((float)$v) ** 2;
        }

        $norm = sqrt($sumSquares);

        // evita divisioni per zero
        if ($norm == 0.0) {
            return $embedding;
        }

        return array_map(
            static fn($v) => (float)$v / $norm,
            $embedding
        );
    }
}
