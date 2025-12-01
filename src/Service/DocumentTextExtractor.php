<?php

namespace App\Service;

use Smalot\PdfParser\Parser as PdfParser;

readonly class DocumentTextExtractor
{
    public function __construct(
        private PdfParser $pdfParser,
    ) {}

    /**
     * Estrae il testo da un file in base all'estensione.
     *
     * Supporta: PDF, MD, ODT, DOCX.
     * Alla fine passa sempre per sanitizeText(), che:
     *  - rimuove emoji
     *  - normalizza spazi
     */
    public function extract(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $text = null;

        switch ($ext) {
            case 'pdf':
                $text = $this->extractPdf($path);
                break;

            case 'md':
                $text = $this->extractMarkdown($path);
                break;

            case 'odt':
                $text = $this->extractOdt($path);
                break;

            case 'docx':
                $text = $this->extractDocx($path);
                break;

            default:
                return null;
        }

        return $this->sanitizeText($text);
    }

    // ======================================================================
    // METODI SPECIFICI PER TIPO DI FILE
    // ======================================================================

    private function extractPdf(string $path): ?string
    {
        try {
            $pdf    = $this->pdfParser->parseFile($path);
            $text   = $pdf->getText();
        } catch (\Throwable $e) {
            // Log, oppure lascia silenzioso
            return null;
        }

        return $text ?: null;
    }

    private function extractMarkdown(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        // Si potrebbe aggiungere qui rimozione di front matter YAML, se usi --- ... ---
        // Esempio molto semplice:
        // if (str_starts_with($content, "---\n")) { ... }

        return $content ?: null;
    }

    private function extractOdt(string $path): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            // Senza zip non posso leggere l'ODT
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }

        $contentXml = $zip->getFromName('content.xml');
        $zip->close();

        if ($contentXml === false) {
            return null;
        }

        // content.xml è XML con tag <text:p>, <text:span>, ecc.
        // Rimuove i tag e normalizzo gli spazi
        $text = strip_tags($contentXml);

        return $text ?: null;
    }

    private function extractDocx(string $path): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            // Anche DOCX è uno zip, senza ZipArchive non posso leggerlo
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }

        $contentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($contentXml === false) {
            return null;
        }

        // Anche qui, è XML con tag <w:p>, <w:t>, ecc.
        $text = strip_tags($contentXml);

        return $text ?: null;
    }

    // ======================================================================
    // SANITIZZAZIONE TESTO (EMOJI, SPAZI, ECC.)
    // ======================================================================

    /**
     * Pulizia finale del testo estratto:
     *  - rimuove emoji e simboli decorativi
     *  - normalizza spazi
     *  - normalizza ritorni a capo multipli
     */
    private function sanitizeText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        // Rimuove emoji e simboli "decorativi"
        $text = $this->stripEmoji($text);

        // Normalizza spazi multipli in uno solo
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Normalizza ritorni a capo multipli (max 2 di fila)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Rimuove la maggior parte degli emoji (range Unicode standard + bandiere + variation selectors).
     */
    private function stripEmoji(string $text): string
    {
        // 1) Tentativo "generico" con property Unicode
        $regexGeneric = '/\p{Extended_Pictographic}|[\x{1F1E6}-\x{1F1FF}]|\x{FE0F}/u';
        $clean = @preg_replace($regexGeneric, '', $text);

        if ($clean !== null) {
            return $clean;
        }

        // 2) Fallback: regex a range, se per qualche motivo la property non è supportata
        $regexFallback = '/['
            . '\x{1F300}-\x{1F5FF}'
            . '\x{1F600}-\x{1F64F}'
            . '\x{1F680}-\x{1F6FF}'
            . '\x{1F700}-\x{1F77F}'
            . '\x{1F780}-\x{1F7FF}'
            . '\x{1F800}-\x{1F8FF}'
            . '\x{1F900}-\x{1F9FF}'
            . '\x{1FA00}-\x{1FA6F}'
            . '\x{1FA70}-\x{1FAFF}'
            . '\x{2600}-\x{26FF}'
            . '\x{2700}-\x{27BF}'
            . '\x{FE00}-\x{FE0F}'
            . '\x{1F1E6}-\x{1F1FF}'
            . ']/u';

        $cleanFallback = preg_replace($regexFallback, '', $text);

        return $cleanFallback ?? $text;
    }

}
