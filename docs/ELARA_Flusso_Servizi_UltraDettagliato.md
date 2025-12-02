# ELARA — Flusso RAG & Servizi Core (ChatbotService + DocumentTextExtractor) ULTRA-dettagliato

## 1. Panoramica architetturale del motore RAG
Il flusso completo:
FILE → Estrattore → Chunking → Embedding → DB → IVF-FLAT → Retrieval → Prompt → AI → Risposta

## 2. ChatbotService — Analisi dettagliata
### 2.1 Costruttore
```php
readonly class ChatbotService {
    public function __construct(
        private EntityManagerInterface  $em,
        private DocumentChunkRepository $chunkRepository,
        private AiClientInterface       $ai
    ) {}
}
```

### 2.2 Metodo ask()
Analisi riga per riga:
- Legge variabili ENV per test mode e fallback.
- Se test mode attivo → answerInTestMode().
- embed() della domanda.
- Ricerca vettoriale via findTopKSimilar().
- Costruzione contesto.
- Chiamata all’AI → ai->chat().
- Gestione exception e fallback offline.

### 2.3 answerInTestMode()
Ricerca testuale LIKE su chunk, restituisce estratti.

### 2.4 answerInOfflineFallback()
Usato se l’AI fallisce. Restituisce estratti dai chunk rilevanti.

### 2.5 buildKeywords()
Normalizza input, rimuove punteggiatura, whitespace, parole corte, duplicati.

---

## 3. DocumentTextExtractor — Analisi ultra dettagliata
### Estrazione testo per estensione:
- PDF: Smalot PdfParser
- Markdown: file_get_contents()
- ODT: ZipArchive + parsing XML
- DOCX: PhpOffice

### sanitizeText()
- Rimuove emoji
- Normalizza whitespace
- Rimuove caratteri invisibili
- Normalizza newline

### stripEmoji()
Regex avanzato con fallback Unicode ranges.

---

## 4. Collegamento servizi
DocumentTextExtractor → IndexDocsCommand  
ChatbotService → ChatController  
AiClientInterface → OpenAiClient / OllamaClient  
DocumentChunkRepository → ricerca vettoriale pgvector.

