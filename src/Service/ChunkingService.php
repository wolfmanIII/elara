<?php

declare(strict_types=1);

namespace App\Service;

class ChunkingService
{
    /**
     * Algoritmo di chunking ottimizzato, che evita chunk troppo corti,
     * include overlap ed è UTF-8 safe. I paragrafi più lunghi di $max
     * vengono spezzati usando splitIntoChunks().
     *
     * @return string[] Elenco di chunk testuali pronti per embedding / RAG
     */
    public function chunkText(
        string $text,
        int $min = 300,
        int $target = 800,
        int $max = 1000,
        int $overlap = 150
    ): array {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // Splitta per paragrafi (due o più newline consecutivi)
        $parts = preg_split("/\R{2,}/u", $text, -1, PREG_SPLIT_NO_EMPTY);

        $chunks = [];
        $buffer = '';

        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }

            $pLen = mb_strlen($p, 'UTF-8');

            // 1) Paragrafo singolo più lungo di $max → spezzalo con splitIntoChunks
            if ($pLen > $max) {
                // Flush del buffer corrente
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }

                // Riusa lo splitter a frasi/parole
                $subChunks = $this->splitIntoChunks($p, $max);
                foreach ($subChunks as $sc) {
                    $sc = trim($sc);
                    if ($sc !== '') {
                        $chunks[] = $sc;
                    }
                }
                continue;
            }

            // 2) Paragrafo "normale": gestito col buffer/min/target/max
            $bufferLen = mb_strlen($buffer, 'UTF-8');

            // Se il paragrafo è troppo corto → accumula nel buffer
            if ($pLen < $min) {
                $buffer .= ($buffer !== '' ? ' ' : '') . $p;
                continue;
            }

            // Se buffer + paragrafo superano max → chiudi chunk corrente e riparti
            if ($bufferLen + $pLen > $max) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                }
                $buffer = $p;
                continue;
            }

            // Aggiungi al buffer
            $buffer .= ($buffer !== '' ? ' ' : '') . $p;

            // Se raggiungiamo il target → chiudiamo il chunk
            if (mb_strlen($buffer, 'UTF-8') >= $target) {
                $chunks[] = $buffer;
                $buffer = '';
            }
        }

        // Flush finale del buffer se rimasto qualcosa
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        // 3) Aggiungi overlap TRA CHUNK, basato su parole
        $final = [];
        $count = count($chunks);

        for ($i = 0; $i < $count; $i++) {
            $chunk = $chunks[$i];

            if ($i > 0 && $overlap > 0) {
                $prev   = $chunks[$i - 1];

                // prefix = ultime parole del chunk precedente
                $prefix = $this->buildWordOverlap($prev, $overlap);

                $chunk = $prefix . $chunk;
            }

            // Fix euristico per spazi mancanti in testo estratto
            $chunk = $this->fixMissingSpaces($chunk);

            $chunk = trim($chunk);
            if ($chunk !== '') {
                $final[] = $chunk;
            }
        }

        return $final;
    }

    /**
     * Split semplice in chunk di ~maxLen caratteri,
     * tagliando su punto o spazio quando possibile
     * e cercando di NON spezzare parole.
     *
     * @return string[]
     */
    private function splitIntoChunks(string $text, int $maxLen): array
    {
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        $chunks = [];
        $len    = mb_strlen($text, 'UTF-8');
        $offset = 0;

        if ($len === 0) {
            return [];
        }

        while ($offset < $len) {
            $remaining = $len - $offset;
            $length    = min($maxLen, $remaining);

            $slice = mb_substr($text, $offset, $length, 'UTF-8');

            // 1) prova ultimo punto nella slice
            $cut = mb_strrpos($slice, '.', 0, 'UTF-8');

            // 2) se niente punto, prova ultimo spazio
            if ($cut === false || $cut <= 0) {
                $cut = mb_strrpos($slice, ' ', 0, 'UTF-8');
            }

            // 3) se ancora niente, estendi fino al prossimo spazio globale
            if ($cut === false || $cut <= 0) {
                $nextSpacePos = mb_strpos($text, ' ', $offset + $length, 'UTF-8');

                if ($nextSpacePos !== false) {
                    $cut = $nextSpacePos - $offset; // taglia dopo la parola
                } else {
                    $cut = $remaining; // nessuno spazio → prendi tutto quello che resta
                }
            }

            $chunkText = trim(mb_substr($text, $offset, $cut, 'UTF-8'));
            if ($chunkText !== '') {
                $chunks[] = $chunkText;
            }

            $offset += $cut;
        }

        return $chunks;
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

        $words = preg_split('/\s+/', $prev, -1, PREG_SPLIT_NO_EMPTY);
        if (!$words || count($words) === 0) {
            return '';
        }

        $selected  = [];
        $totalLen  = 0;

        // parti dalla fine e risali
        for ($i = count($words) - 1; $i >= 0; $i--) {
            $w    = $words[$i];
            $wLen = mb_strlen($w, 'UTF-8');

            // +1 per lo spazio che aggiungeremo tra le parole
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

        return implode(' ', $selected) . ' ';
    }

    /**
     * Fix euristico per rimettere spazi dove l'estrazione (PDF/Docx)
     * ha incollato parole e frasi.
     */
    private function fixMissingSpaces(string $text): string
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
