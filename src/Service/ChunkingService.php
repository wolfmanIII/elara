<?php

declare(strict_types=1);

namespace App\Service;

class ChunkingService
{
    /**
     * Limite assoluto di sicurezza sulla lunghezza di un chunk (in caratteri).
     * Serve ad evitare di mandare a Ollama input troppo lunghi, che possono
     * generare errori tipo:
     *
     *   "panic: caching disabled but unable to fit entire input in a batch"
     */
    private const HARD_MAX_CHARS = 1500;

    /**
     * Algoritmo di chunking:
     * - sistema alcuni spazi mancanti (da PDF/OCR) con fixMissingSpaces()
     * - splitta per paragrafi (2+ newline consecutivi)
     * - per ogni paragrafo crea chunk usando frasi/parole, rispettando:
     *     - $max come limite “logico”
     *     - HARD_MAX_CHARS come limite assoluto
     * - fa una pass veloce per evitare un ultimo chunk ridicolmente corto
     * - aggiunge overlap tra chunk (basato su parole) senza superare HARD_MAX_CHARS
     *
     * @return string[] Elenco di chunk testuali pronti per embedding / RAG
     */
    public function chunkText(
        string $text,
        int $min = 400,
        int $max = 1500,
        int $overlap = 250
    ): array {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // Prova a correggere alcuni difetti tipici del testo estratto da PDF
        $text = $this->fixMissingSpaces($text);

        // 1) Splitta per paragrafi (due o più newline consecutivi)
        $parts = preg_split("/\R{2,}/u", $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $baseChunks = [];
        $buffer     = '';

        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }

            $pLen = mb_strlen($p, 'UTF-8');

            // Se il paragrafo è già troppo lungo, lo spezzettiamo subito
            if ($pLen > $max) {
                // Flush eventuale del buffer corrente
                if ($buffer !== '') {
                    foreach ($this->splitIntoChunks($buffer, $max) as $chunk) {
                        $chunk = trim($chunk);
                        if ($chunk !== '') {
                            $baseChunks[] = $chunk;
                        }
                    }
                    $buffer = '';
                }

                foreach ($this->splitIntoChunks($p, $max) as $chunk) {
                    $chunk = trim($chunk);
                    if ($chunk !== '') {
                        $baseChunks[] = $chunk;
                    }
                }

                continue;
            }

            // Proviamo ad accumulare paragrafi nel buffer finché restiamo <= $max
            if ($buffer === '') {
                $buffer = $p;
                continue;
            }

            $candidate = $buffer . "\n\n" . $p;
            $len       = mb_strlen($candidate, 'UTF-8');

            if ($len <= $max) {
                // Ci sta ancora nel chunk "ideale"
                $buffer = $candidate;
            } else {
                // Il nuovo paragrafo farebbe sforare $max
                // → chiudiamo il buffer attuale come chunk
                foreach ($this->splitIntoChunks($buffer, $max) as $chunk) {
                    $chunk = trim($chunk);
                    if ($chunk !== '') {
                        $baseChunks[] = $chunk;
                    }
                }

                // e mettiamo il paragrafo corrente in un nuovo buffer
                $buffer = $p;
            }
        }

        // Flush finale del buffer, se è rimasto qualcosa
        if ($buffer !== '') {
            foreach ($this->splitIntoChunks($buffer, $max) as $chunk) {
                $chunk = trim($chunk);
                if ($chunk !== '') {
                    $baseChunks[] = $chunk;
                }
            }
        }

        // 2) Se l'ultimo chunk è troppo corto rispetto al minimo, uniscilo al precedente
        $baseChunks = $this->mergeLastIfTooShort($baseChunks, $min);

        // 3) Aggiungi overlap tra chunk basato su parole, ma senza superare HARD_MAX_CHARS
        $finalChunks = $this->applyOverlap($baseChunks, $overlap);

        return $finalChunks;
    }

    /**
     * Spezza una stringa in chunk "ragionevoli" usando frasi e, se necessario, parole.
     * Garantisce che nessun chunk superi HARD_MAX_CHARS.
     *
     * @return string[]
     */
    private function splitIntoChunks(string $text, int $maxLen): array
    {
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        // Non andiamo mai oltre l'hard limit assoluto
        $maxLen = min($maxLen, self::HARD_MAX_CHARS);

        // 1) Prova a splittare per frasi
        $sentences = preg_split(
            '/(?<=[\.!?])\s+/u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [$text];

        $chunks = [];
        $buffer = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $sLen = mb_strlen($sentence, 'UTF-8');

            // Se la singola frase è già più lunga di $maxLen, spezza per parole
            if ($sLen > $maxLen) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer   = '';
                }

                foreach ($this->splitByWords($sentence, $maxLen) as $wChunk) {
                    $wChunk = trim($wChunk);
                    if ($wChunk !== '') {
                        $chunks[] = $wChunk;
                    }
                }

                continue;
            }

            // Prova ad aggiungerla al buffer
            $candidate = $buffer === '' ? $sentence : $buffer . ' ' . $sentence;
            $len       = mb_strlen($candidate, 'UTF-8');

            if ($len <= $maxLen) {
                $buffer = $candidate;
            } else {
                // Il buffer attuale va bene, chiudilo e ricomincia
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                }
                $buffer = $sentence;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        // Safety finale: tutto comunque <= HARD_MAX_CHARS
        $safe = [];
        foreach ($chunks as $c) {
            $cLen = mb_strlen($c, 'UTF-8');
            if ($cLen <= self::HARD_MAX_CHARS) {
                $safe[] = $c;
                continue;
            }

            foreach ($this->splitByWords($c, self::HARD_MAX_CHARS) as $wChunk) {
                $wChunk = trim($wChunk);
                if ($wChunk !== '') {
                    $safe[] = $wChunk;
                }
            }
        }

        return $safe;
    }

    /**
     * Split "brutale" per parole, garantendo chunk <= $maxLen.
     *
     * @return string[]
     */
    private function splitByWords(string $text, int $maxLen): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $chunks = [];
        $buffer = '';

        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }

            $candidate = $buffer === '' ? $word : $buffer . ' ' . $word;
            $len       = mb_strlen($candidate, 'UTF-8');

            if ($len <= $maxLen) {
                $buffer = $candidate;
                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
            }

            // Se la singola parola supera il limite, taglio brutale
            if (mb_strlen($word, 'UTF-8') > $maxLen) {
                $chunks[] = mb_substr($word, 0, $maxLen, 'UTF-8');
                $buffer   = '';
            } else {
                $buffer = $word;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    /**
     * Unisce l’ultimo chunk al precedente se è molto più corto del minimo desiderato.
     */
    private function mergeLastIfTooShort(array $chunks, int $min): array
    {
        $count = count($chunks);
        if ($count < 2) {
            return $chunks;
        }

        $last     = $chunks[$count - 1];
        $lastLen  = mb_strlen($last, 'UTF-8');

        if ($lastLen >= $min) {
            return $chunks;
        }

        $prev     = $chunks[$count - 2];
        $merged   = $prev . "\n\n" . $last;
        $mergedLen = mb_strlen($merged, 'UTF-8');

        if ($mergedLen <= self::HARD_MAX_CHARS) {
            $chunks[$count - 2] = $merged;
            array_pop($chunks);
        }

        return $chunks;
    }

    /**
     * Applica overlap tra chunk, usando le *ultime parole* del chunk precedente.
     * L'overlap è espresso in "caratteri obiettivo", non in numero di parole.
     * Si assicura di non superare HARD_MAX_CHARS.
     *
     * @param string[] $chunks
     * @return string[]
     */
    private function applyOverlap(array $chunks, int $overlapChars): array
    {
        if ($overlapChars <= 0 || count($chunks) === 0) {
            return $chunks;
        }

        $final = [];
        $count = count($chunks);

        for ($i = 0; $i < $count; $i++) {
            $chunk = trim($chunks[$i]);
            if ($chunk === '') {
                continue;
            }

            // Nessun overlap per il primo chunk
            if ($i === 0) {
                $final[] = $chunk;
                continue;
            }

            $prev = $chunks[$i - 1];

            // Quanto spazio abbiamo per il prefisso, restando entro HARD_MAX_CHARS?
            $chunkLen  = mb_strlen($chunk, 'UTF-8');
            $available = self::HARD_MAX_CHARS - $chunkLen - 2; // -2 per "\n\n"

            if ($available <= 0) {
                // Non c'è spazio per overlap, teniamo solo il chunk
                $final[] = $chunk;
                continue;
            }

            // L'overlap reale è il min tra richiesto e disponibile
            $effectiveOverlap = min($overlapChars, $available);

            $prefix = $this->buildWordOverlap($prev, $effectiveOverlap);
            if ($prefix === '') {
                $final[] = $chunk;
                continue;
            }

            $candidate = $prefix . "\n\n" . $chunk;

            // Safety extra, nel caso l'overlap sia ancora troppo grande
            if (mb_strlen($candidate, 'UTF-8') > self::HARD_MAX_CHARS) {
                $final[] = $chunk;
                continue;
            }

            $final[] = $candidate;
        }

        return $final;
    }

    /**
     * Costruisce un overlap basato su parole (non su caratteri).
     * Prende le ultime parole del chunk precedente finché non
     * supera approssimativamente overlapChars caratteri.
     */
    private function buildWordOverlap(string $prev, int $overlapChars): string
    {
        $prev = trim($prev);
        if ($overlapChars <= 0 || $prev === '') {
            return '';
        }

        $words = preg_split('/\s+/u', $prev, -1, PREG_SPLIT_NO_EMPTY);
        if (!$words || count($words) === 0) {
            return '';
        }

        $selected = [];
        $totalLen = 0;

        // Parti dalla fine e risali
        for ($i = count($words) - 1; $i >= 0; $i--) {
            $w = $words[$i];

            $wLen = mb_strlen($w, 'UTF-8');

            // +1 per lo spazio che si aggiunge tra le parole
            if ($totalLen > 0) {
                $wLen += 1;
            }

            if ($totalLen + $wLen > $overlapChars && !empty($selected)) {
                break;
            }

            array_unshift($selected, $w);
            $totalLen += $wLen;

            if ($totalLen >= $overlapChars) {
                break;
            }
        }

        return implode(' ', $selected);
    }

    /**
     * Corregge alcuni casi tipici di "spazi mancanti" dovuti all'estrazione da PDF:
     *  1) Nessuno spazio dopo . ! ? ; :
     *  2) ALL-CAPS subito seguite da parola capitalizzata (MOTIVAZIONIRuolo)
     *  3) minuscola seguita da maiuscola senza spazio (standard.Origini)
     */
    public function fixMissingSpaces(string $text): string
    {
        // 1) Spazio dopo ., !, ?, ;, : se NON c'è già uno spazio
        // es: "dominanti:Carisma" -> "dominanti: Carisma"
        $text = preg_replace(
            '/([\.!?;:])([^\s])/u',
            '$1 $2',
            $text
        );

        // 2) Spazio tra parola ALL-CAPS e parola Capitalized attaccate
        // es: "MOTIVAZIONIRuolo" -> "MOTIVAZIONI Ruolo"
        //     "PSICOLOGICOEtà"   -> "PSICOLOGICO Età"
        $text = preg_replace(
            '/\b([A-ZÀ-ÖØ-Ý]{2,})([A-ZÀ-ÖØ-Ý][a-zà-öø-ÿ]+)/u',
            '$1 $2',
            $text
        );

        // 3) Spazio tra minuscola e maiuscola attaccate (caso generico)
        // es: "standard.Origini" -> "standard. Origini"
        $text = preg_replace(
            '/([\p{Ll}])([\p{Lu}])/u',
            '$1 $2',
            $text
        );

        return $text;
    }
}
