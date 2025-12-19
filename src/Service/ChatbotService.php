<?php

declare(strict_types=1);

namespace App\Service;

use App\AI\AiClientInterface;
use App\Entity\DocumentChunk;
use App\Rag\RagProfileManager;
use Doctrine\ORM\EntityManagerInterface;

class ChatbotService
{
    public function __construct(
        private EntityManagerInterface  $em,
        private AiClientInterface       $ai,
        private RagProfileManager       $profiles,
    ) {}

    /**
     * Entry point principale chiamato dal controller.
     */
    public function ask(string $question): array
    {
        $aiConfig = $this->profiles->getAi();
        $retrievalConfig = $this->profiles->getRetrieval();
        $testMode = (bool) ($aiConfig['test_mode'] ?? false);
        $offlineFallbackEnabled = (bool) ($aiConfig['offline_fallback'] ?? true);
        // Modalità test: niente AI, solo ricerca testuale nel DB.
        if ($testMode) {
            return [
                'answer' => $this->answerInTestMode($question),
                'sources' => [],
            ];
        }

        try {
            // 1) Embedding della domanda (usa il backend configurato, es. Ollama o OpenAI)
            $queryVec = $this->ai->embed($question);

            // 2) recupera chunk più simili (top 5) usando cosine_similarity
            $chunks = $this->em->getRepository(DocumentChunk::class)->findTopKCosineSimilarity(
                $queryVec,
                topK: (int) ($retrievalConfig['top_k'] ?? 5),
                minScore: (float) ($retrievalConfig['min_score'] ?? 0.55),
            );
            if (!$chunks) {
                return [
                    'answer' => 'Non trovo informazioni rilevanti nei documenti indicizzati.',
                    'sources' => [],
                ];
            }

            // 3) Costruisco il contesto per il modello
            [$context, $sources] = $this->buildContextPayload($chunks);

            // 4) Lascio che il backend AI generi la risposta usando contesto + domanda
            $answer = $this->ai->chat($question, $context, null);

            return [
                'answer' => $answer !== '' ? $answer : 'Non sono riuscito a generare una risposta.',
                'sources' => $sources,
            ];
        } catch (\Throwable $e) {
            if ($offlineFallbackEnabled) {
                return [
                    'answer' => $this->answerInOfflineFallback($question, $e),
                    'sources' => [],
                ];
            }

            return [
                'answer' => 'Errore nella chiamata al servizio AI: ' . $e->getMessage() . $e->getTraceAsString(),
                'sources' => [],
            ];
        }
    }

    /**
     * Versione streaming: inoltra ogni chunk di risposta al callback fornito.
     *
     * @param callable(string $chunk): void $onChunk
     */
    public function askStream(string $question, callable $onChunk): array
    {
        $aiConfig = $this->profiles->getAi();
        $retrievalConfig = $this->profiles->getRetrieval();
        $testMode = (bool) ($aiConfig['test_mode'] ?? false);
        $offlineFallbackEnabled = (bool) ($aiConfig['offline_fallback'] ?? true);
        if ($testMode) {
            $onChunk($this->answerInTestMode($question));
            return [];
        }

        try {
            $queryVec = $this->ai->embed($question);
            $chunks = $this->em->getRepository(DocumentChunk::class)->findTopKCosineSimilarity(
                $queryVec,
                topK: (int) ($retrievalConfig['top_k'] ?? 5),
                minScore: (float) ($retrievalConfig['min_score'] ?? 0.55),
            );

            if (!$chunks) {
                $onChunk('Non trovo informazioni rilevanti nei documenti indicizzati.');
                return [];
            }

            [$context, $sources] = $this->buildContextPayload($chunks);

            $this->ai->chatStream($question, $context, null, $onChunk);
            return $sources;
        } catch (\Throwable $e) {
            if ($offlineFallbackEnabled) {
                $onChunk($this->answerInOfflineFallback($question, $e));
                return [];
            }

            $onChunk('Errore nella chiamata al servizio AI: ' . $e->getMessage());
            return [];
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
     * prova comunque a dare almeno degli estratti dai documenti locali.
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
     * @param array<int, array<string, mixed>> $chunks
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function buildContextPayload(array $chunks): array
    {
        $context = '';
        $sources = [];

        foreach ($chunks as $chunk) {
            $similarityFloat = (float) ($chunk['similarity'] ?? 0);
            $similarityFormatted = number_format($similarityFloat, 2, ',', '.');
            $filePath = (string) $chunk['file_path'];
            $chunkIndex = (int) $chunk['chunk_index'];

            $context .= sprintf(
                "Fonte: %s - chunk %d - similarity %s\n%s\n\n",
                $filePath,
                $chunkIndex,
                $similarityFormatted,
                (string) $chunk['chunk_content']
            );

            $sources[] = [
                'file' => $filePath,
                'chunk' => $chunkIndex,
                'similarity' => $similarityFloat,
                'similarity_formatted' => $similarityFormatted,
                'preview' => $this->makePreview((string) $chunk['chunk_content']),
            ];
        }

        return [$context, $sources];
    }

    private function makePreview(string $text, int $maxLength = 240): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (mb_strlen($clean, 'UTF-8') <= $maxLength) {
            return $clean;
        }

        return mb_substr($clean, 0, $maxLength, 'UTF-8') . '…';
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
