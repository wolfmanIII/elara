<?php

declare(strict_types=1);

namespace App\Service;

use App\Rag\RagProfileManager;
use IntlBreakIterator;

class ChunkingService
{
    /**
     * Limite assoluto di sicurezza. Nessun chunk può mai superare questo valore.
     */
    private const HARD_MAX_CHARS = 1500;

    private RagProfileManager $profiles;

    public function __construct(RagProfileManager $profiles)
    {
        $this->profiles = $profiles;
    }

    /**
     * Algoritmo di chunking moderno (Intl) che rispetta la configurazione del profilo.
     */
    public function chunkText(
        string $text,
        ?int $min = null,
        ?int $max = null,
        ?int $overlap = null
    ): array {
        // 1. Recupero configurazione
        $chunkConfig = $this->profiles->getChunking();
        $min       ??= (int) ($chunkConfig['min'] ?? 400);
        $max       ??= (int) ($chunkConfig['max'] ?? 1000);
        $overlap   ??= (int) ($chunkConfig['overlap'] ?? 200);

        // Safety check sul max per non superare mai l'hard limit
        $max = min($max, self::HARD_MAX_CHARS);

        // 2. Pulizia preliminare
        $text = $this->cleanText($text);
        if ($text === '') {
            return [];
        }

        // 3. Generazione dei chunk base (rispettando SOLO $max per ora)
        $baseChunks = $this->createBaseChunks($text, $max);

        // 4. Applicazione logica MinLen (unisce l'ultimo chunk se troppo corto)
        $baseChunks = $this->mergeLastIfTooShort($baseChunks, $min);

        // 5. Applicazione Overlap
        return $this->applyOverlap($baseChunks, $overlap);
    }

    /**
     * Crea i chunk primari usando IntlBreakIterator invece delle Regex.
     * Rispetta rigorosamente $maxLen.
     */
    private function createBaseChunks(string $text, int $maxLen): array
    {
        $iterator = IntlBreakIterator::createSentenceInstance('it_IT');
        $iterator->setText($text);

        $chunks = [];
        $currentBuffer = '';

        foreach ($iterator->getPartsIterator() as $sentence) {
            $sentenceLen = mb_strlen($sentence);

            // CASO A: La frase singola è più lunga del massimo consentito?
            // Dobbiamo spezzarla forzatamente (fallback sulle parole)
            if ($sentenceLen > $maxLen) {
                // Se c'era qualcosa nel buffer, salviamolo prima
                if ($currentBuffer !== '') {
                    $chunks[] = trim($currentBuffer);
                    $currentBuffer = '';
                }

                // Spezza la frase gigante e aggiungi i pezzi
                $subChunks = $this->splitLargeSentence($sentence, $maxLen);
                $chunks = array_merge($chunks, $subChunks);
                continue;
            }

            // CASO B: La frase ci sta nel buffer corrente?
            // Nota: +1 considererebbe lo spazio virtuale tra frasi, ma Intl lo include spesso nella frase prec.
            if (mb_strlen($currentBuffer . $sentence) <= $maxLen) {
                $currentBuffer .= $sentence;
            } else {
                // CASO C: Il buffer è pieno -> Flush
                $chunks[] = trim($currentBuffer);
                $currentBuffer = $sentence;
            }
        }

        if (trim($currentBuffer) !== '') {
            $chunks[] = trim($currentBuffer);
        }

        return $chunks;
    }

    /**
     * Se l'ultimo chunk è misero (< min), prova ad accorparlo al penultimo,
     * a patto di non sfondare HARD_MAX_CHARS.
     */
    private function mergeLastIfTooShort(array $chunks, int $minLen): array
    {
        $count = count($chunks);
        if ($count < 2) {
            return $chunks;
        }

        $lastIdx = $count - 1;
        $lastChunk = $chunks[$lastIdx];

        // Se l'ultimo chunk è già abbastanza lungo, non facciamo nulla
        if (mb_strlen($lastChunk) >= $minLen) {
            return $chunks;
        }

        // Proviamo a unire con il penultimo
        $prevIdx = $count - 2;
        $prevChunk = $chunks[$prevIdx];
        
        // Calcoliamo la lunghezza unita (aggiungendo uno spazio o newline separatore)
        $mergedText = $prevChunk . ' ' . $lastChunk;

        // Se l'unione è sicura (non supera il limite hardware), procediamo
        if (mb_strlen($mergedText) <= self::HARD_MAX_CHARS) {
            $chunks[$prevIdx] = $mergedText;
            unset($chunks[$lastIdx]);
            // Reindicizza l'array per evitare buchi negli indici
            return array_values($chunks); 
        }

        return $chunks;
    }

    /**
     * Applica l'overlap usando mb_substr (molto più veloce dello split per parole).
     * Prende la coda del chunk N-1 e la incolla in testa al chunk N.
     */
    private function applyOverlap(array $chunks, int $overlapChars): array
    {
        if ($overlapChars <= 0 || count($chunks) < 2) {
            return $chunks;
        }

        $result = [];
        $result[] = $chunks[0]; // Il primo non ha overlap

        for ($i = 1; $i < count($chunks); $i++) {
            $prevChunk = $chunks[$i - 1];
            $currChunk = $chunks[$i];

            // Calcola quanto spazio abbiamo nel chunk corrente prima di esplodere
            $currentLen = mb_strlen($currChunk);
            $availableSpace = self::HARD_MAX_CHARS - $currentLen - 1; // -1 per lo spazio

            if ($availableSpace <= 0) {
                $result[] = $currChunk;
                continue;
            }

            // L'overlap effettivo non può superare lo spazio disponibile
            $effectiveOverlap = min($overlapChars, $availableSpace);

            // Estrae la coda del precedente
            $overlapText = $this->extractCleanTail($prevChunk, $effectiveOverlap);

            if ($overlapText !== '') {
                $result[] = $overlapText . ' ' . $currChunk;
            } else {
                $result[] = $currChunk;
            }
        }

        return $result;
    }

    /**
     * Estrae gli ultimi N caratteri, ma tagliando all'inizio di una parola
     * per evitare troncatu... (troncature).
     */
    private function extractCleanTail(string $text, int $length): string
    {
        if ($length <= 0) return '';
        
        $textLen = mb_strlen($text);
        if ($textLen <= $length) return $text;

        // Prendi la sottostringa finale grezza
        $substr = mb_substr($text, -$length);

        // Cerca il primo spazio per allinearsi all'inizio di una parola
        $firstSpace = mb_strpos($substr, ' ');

        if ($firstSpace !== false) {
            return trim(mb_substr($substr, $firstSpace + 1));
        }

        // Se non trova spazi (parola lunghissima), ritorna tutto per non perdere info
        return $substr;
    }

    /**
     * Fallback per frasi giganti (usa iteratore di parole).
     */
    private function splitLargeSentence(string $sentence, int $maxLen): array
    {
        $iterator = IntlBreakIterator::createWordInstance('it_IT');
        $iterator->setText($sentence);

        $chunks = [];
        $current = '';

        foreach ($iterator->getPartsIterator() as $word) {
            if (mb_strlen($current . $word) > $maxLen) {
                if (trim($current) !== '') {
                    $chunks[] = trim($current);
                }
                $current = $word;
            } else {
                $current .= $word;
            }
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    /**
     * Pulizia Regex ottimizzata (PDF Fixes).
     */
    private function cleanText(string $text): string
    {
        // 1. Normalizza spazi
        $text = preg_replace('/\s+/u', ' ', $text);

        // 2. Fix punteggiatura attaccata
        $text = preg_replace('/(?<=[.!?;:])(?=[^\s])/u', ' ', $text);

        // 3. Fix CamelCase errato (PDF headers)
        $text = preg_replace('/([A-ZÀ-ÖØ-Ý]{2,})([A-ZÀ-ÖØ-Ý][a-zà-öø-ÿ]+)/u', '$1 $2', $text);

        // 4. Fix lowerUpper case
        $text = preg_replace('/([\p{Ll}])([\p{Lu}])/u', '$1 $2', $text);

        return trim($text ?? '');
    }
}