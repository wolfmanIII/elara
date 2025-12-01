<?php

namespace App\Service;

use App\AI\AiClientInterface;
use App\Entity\DocumentChunk;
use App\Repository\DocumentChunkRepository;
use Doctrine\ORM\EntityManagerInterface;

readonly class ChatbotService
{
    public function __construct(
        private EntityManagerInterface  $em,
        private DocumentChunkRepository $chunkRepository,
        private AiClientInterface       $ai,
    ) {}

    /**
     * Entry point principale chiamato dal controller.
     */
    public function ask(string $question): string
    {
        $testMode = ($_ENV['APP_AI_TEST_MODE'] ?? 'false') === 'true';
        $offlineFallbackEnabled =
            ($_ENV['APP_AI_OFFLINE_FALLBACK'] ?? 'true') === 'true';

        // Modalità test: niente AI, solo ricerca testuale nel DB.
        if ($testMode) {
            return $this->answerInTestMode($question);
        }

        try {
            // 1) Embedding della domanda (usa il backend configurato, es. Ollama o OpenAI)
            $queryVec = $this->ai->embed($question);

            // vecchia versione 2) recupero chunk più simili (top 5) usando cosine_similarity
            /*
            $chunks = $this->em->getRepository(DocumentChunk::class)->findTopSimilarByCosineSimilarity($queryVec, 5);
            if (!$chunks) {
                return 'Non trovo informazioni rilevanti nei documenti indicizzati.';
            }
            $context = '';
            foreach ($chunks as $chunk) {
                $file = $chunk->getFile();
                $context .= "Fonte: ".$file->getPath()." (chunk ".$chunk->getChunkIndex().")\n";
                $context .= $chunk->getContent()."\n\n";
            }
            */

            // 2) Recupero i chunk più simili usando il repository ottimizzato (ivfflat + <=>)
            $topChunks = $this->chunkRepository->findTopKSimilar($queryVec, 5);

            if (!$topChunks) {
                return 'Non trovo informazioni rilevanti nei documenti indicizzati.';
            }

            // 3) Costruisco il contesto per il modello
            $context = '';
            foreach ($topChunks as $row) {
                $filePath   = $row['file_path'] ?? 'sconosciuto';
                $chunkIndex = $row['chunk_index'] ?? 0;
                $distance   = $row['distance'] ?? null;

                $distanceInfo = $distance !== null
                    ? sprintf(' (distanza %.4f)', $distance)
                    : '';

                $context .= "Fonte: {$filePath} (chunk {$chunkIndex}{$distanceInfo})\n";
                $context .= ($row['content'] ?? '') . "\n\n";
            }

            // 4) Lascio che il backend AI generi la risposta usando contesto + domanda
            $answer = $this->ai->chat($question, $context);

            return $answer !== '' ? $answer : 'Non sono riuscito a generare una risposta.';
        } catch (\Throwable $e) {
            if ($offlineFallbackEnabled) {
                return $this->answerInOfflineFallback($question, $e);
            }

            return 'Errore nella chiamata al servizio AI: ' . $e->getMessage();
        }
    }

    /**
     * Modalità test: non chiama alcun modello AI.
     * Usa solo query LIKE sul contenuto dei chunk per estrarre estratti rilevanti.
     */
    private function answerInTestMode(string $question): string
    {
        $keywords = $this->buildKeywords($question);

        $qb = $this->em->createQueryBuilder()
            ->select('c', 'f')
            ->from(DocumentChunk::class, 'c')
            ->join('c.file', 'f')
            ->setMaxResults(5);

        if ($keywords) {
            $expr = $qb->expr();
            $orX  = $expr->orX();

            foreach ($keywords as $idx => $kw) {
                $paramName = 'k' . $idx;
                $orX->add($expr->like('LOWER(c.content)', ':' . $paramName));
                $qb->setParameter($paramName, '%' . $kw . '%');
            }

            $qb->where($orX);
        } else {
            $qb->where('LOWER(c.content) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($question) . '%');
        }

        /** @var DocumentChunk[] $chunks */
        $chunks = $qb->getQuery()->getResult();

        if (!$chunks) {
            return "[TEST MODE] Nessun documento sembra contenere la query.\n\nDomanda: " . $question;
        }

        $out = "[TEST MODE] Non sto chiamando nessun servizio AI.\n";
        $out .= "Questi sono alcuni estratti che sembrano rilevanti:\n\n";

        foreach ($chunks as $chunk) {
            $file    = $chunk->getFile();
            $preview = mb_substr($chunk->getContent(), 0, 300);

            $out .= "- Fonte: " . $file->getPath() . " (chunk " . $chunk->getChunkIndex() . ")\n";
            $out .= "  Estratto: " . str_replace("\n", ' ', $preview) . "…\n\n";
        }

        return $out;
    }

    /**
     * Modalità fallback: se la chiamata al modello AI fallisce per errore tecnico,
     * proviamo comunque a dare almeno degli estratti dai documenti locali.
     */
    private function answerInOfflineFallback(string $question, \Throwable $e): string
    {
        $keywords = $this->buildKeywords($question);

        $qb = $this->em->createQueryBuilder()
            ->select('c', 'f')
            ->from(DocumentChunk::class, 'c')
            ->join('c.file', 'f')
            ->setMaxResults(5);

        if ($keywords) {
            $expr = $qb->expr();
            $orX  = $expr->orX();

            foreach ($keywords as $idx => $kw) {
                $paramName = 'k' . $idx;
                $orX->add($expr->like('LOWER(c.content)', ':' . $paramName));
                $qb->setParameter($paramName, '%' . $kw . '%');
            }

            $qb->where($orX);
        } else {
            $qb->where('LOWER(c.content) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($question) . '%');
        }

        /** @var DocumentChunk[] $chunks */
        $chunks = $qb->getQuery()->getResult();

        if (!$chunks) {
            return "Il servizio AI non è raggiungibile e non trovo nulla nei documenti locali per la tua domanda.\n"
                . "Dettaglio tecnico: " . $e->getMessage();
        }

        $out = "Il servizio AI non è raggiungibile in questo momento, "
            . "ma ho trovato alcuni estratti nei documenti locali:\n\n";

        foreach ($chunks as $chunk) {
            $file    = $chunk->getFile();
            $preview = mb_substr($chunk->getContent(), 0, 300);

            $out .= "- Fonte: " . $file->getPath() . " (chunk " . $chunk->getChunkIndex() . ")\n";
            $out .= "  Estratto: " . str_replace("\n", ' ', $preview) . "…\n\n";
        }

        $out .= "\n(Dettaglio tecnico: " . $e->getMessage() . ")";

        return $out;
    }

    /**
     * Estrae keyword semplici dalla domanda per le query LIKE
     * (usato in modalità test e in offline fallback).
     */
    private function buildKeywords(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        if ($text === null) {
            return [];
        }

        $parts = preg_split('/\s+/', trim($text));
        if (!$parts) {
            return [];
        }

        $keywords = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) < 3) {
                continue;
            }
            $keywords[] = $p;
        }

        return array_values(array_unique($keywords));
    }
}
