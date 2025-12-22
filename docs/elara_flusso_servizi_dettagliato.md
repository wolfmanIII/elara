# ELARA — Flusso dei Servizi (Versione Completa e Aggiornata)

L’obiettivo è descrivere **in modo chiaro, esaustivo e moderno** il funzionamento dei servizi core dell’applicazione.

---

# 1. Panoramica dei Servizi
I servizi in ELARA sono organizzati per responsabilità verticali e indipendenti. Ogni servizio è progettato per essere:
- **autonomo**,
- **testabile**,
- **coerente** con una singola responsabilità.

I principali macro-servizi sono:
- AI (embedding + chat),
- estrazione,
- chunking,
- repository vettoriali,
- middleware database,
- generazione risposte,
- **orchestrazione dell’indicizzazione (DocsIndexer)**.

---

# 2. AiClientInterface e Implementazioni
L’interfaccia **AiClientInterface** definisce 3 funzioni fondamentali:

```
embed(string|array $text): array;

chat(string $question, string $context, ?string $source): string;

chatStream(
        string $question,
        string $context,
        ?string $source,
        callable $onChunk
    ): void;
```

## 2.1 Implementazioni
### **OpenAiClient**
- usa l’API OpenAI ufficiale,
- altissima qualità nelle risposte,
- embedding fino a 1536 dimensioni,
- costo a consumo.

### **GeminiClient**
- usa l’API Gemini ufficiale,
- altissima qualità nelle risposte,
- embedding fino a 1536|3072 dimensioni,
- costo a consumo.

### **OllamaClient**
- gira in locale tramite Ollama,
- nessun costo,
- embedding 1024 (bge-m3),
- utile per ambienti self-hosted.

Il backend è configurabile tramite i preset dichiarati in `config/packages/rag_profiles.yaml` (variabile `RAG_PROFILE=<nome>` o opzione CLI `--rag-profile=<nome>`); ogni profilo definisce backend, modelli chat/embedding e flag di fallback.

### 2.2 RagProfileManager e ActiveProfileStorage
- `RagProfileManager` centralizza i preset RAG e fornisce ai servizi (ChatbotService, DocumentChunkRepository, ChunkingService) i parametri correnti di chunking, retrieval (`top_k`, `min_score`) e AI.
- `ActiveProfileStorage` persiste la scelta del profilo su filesystem, così la selezione resta tra riavvii ed è condivisa fra UI e CLI.
- La UI “RAG Profiles” usa un controller Stimulus per lo switch (disabilita i bottoni per evitare doppi invii) e mostra un alert quando la dimensione embedding del profilo non coincide con `DocumentChunk->embedding`, suggerendo reset schema + reindex.

---

# 3. DocumentTextExtractor
Servizio responsabile della fase di estrazione e pulizia del testo.

## 3.1 Funzionalità principali
- Identificazione tipo file.
- Estrazione testo da PDF, ODT, DOCX, MD.
- Normalizzazione avanzata del contenuto.

## 3.2 Normalizzazioni applicate
- trim globale,
- rimozione multipli whitespace,
- rimozione caratteri invisibili Unicode,
- rimozione emoji e simboli grafici,
- correzione automatica degli spazi mancanti,
- correzione di MAIUSCOLO+Capitalized.

## 3.3 Obiettivo
Produrre un testo coerente, lineare e adatto al chunking.

---

# 4. ChunkingService
Elemento centrale del flusso.

## 4.1 Funzioni chiave
- split per paragrafi (2+ newline),
- split in frasi e parole,
- merge chunk corti,
- overlap intelligente basato su numero di caratteri,
- limite assoluto HARD_MAX_CHARS.

## 4.2 Parametri di default consigliati
- `min = 400–500`,
- `target = 1200–1600`,
- `max = 1500–1800`,
- `overlap = 200–300`,
- `HARD_MAX_CHARS = 1500`.

## 4.3 Obiettivo
Creare chunk omogenei, semanticamente coerenti, adatti a creare embedding di alta qualità.

---

# 5. DocumentChunkRepository
Repository Doctrine che gestisce la ricerca vettoriale.

## 5.1 Ricerca per similarità
La query chiave è:
```
ORDER BY embedding <=> :vec
```
Usa la **cosine distance** per confrontare embedding.

## 5.2 Parametri essenziali
- **top_k**: numero di chunk da recuperare (3–6),
- **soglia** di similarità (es. > 0.55),
- eventuale filtro per documento.

## 5.3 Obiettivo
Restituire solo i chunk più rilevanti, che saranno poi forniti al modello LLM.

---

# 6. PgvectorIvfflatMiddleware
Middleware opzionale che gestisce la ricostruzione dell’indice vettoriale.

## 6.1 Funzioni
- gestione `REINDEX` automatico in caso di indicizzazione massiva,
- parametrizzazione `lists` e `probes` (solo IVF-FLAT),
- creazione indici personalizzati.

## 6.2 Indici disponibili
- **HNSW** → raccomandato,
- **IVF-FLAT** → solo dataset molto grandi.

## 6.3 Best practice
**Mai usare entrambi gli indici sulla stessa colonna**, per motivi di:
- performance,
- coerenza,
- recall instabile.

---

# 7. ChatbotService
Il cuore della fase runtime.

## 7.1 Costruttore
```
ChatbotService(
    EntityManagerInterface  $em,
    DocumentChunkRepository $chunkRepository,
    AiClientInterface       $ai
)
```

## 7.2 Metodo ask() — Flusso dettagliato
1. **Lettura configurazioni ENV** (test mode, fallback).  
2. **Embedding domanda** tramite AiClientInterface.  
3. **Ricerca vettoriale** via DocumentChunkRepository.  
4. **Costruzione contesto RAG** concatenando i chunk.  
5. **Costruzione prompt** con system + user.  
6. **Chiamata backend AI**.  
7. **Gestione eccezioni**.  
8. **Fallback** (modalità offline).

## 7.3 Metodi speciali
### `answerInTestMode()`
- Nessun uso AI.
- Ricerca testuale LIKE.
- Utile per verificare la bontà della domanda.

### `answerInOfflineFallback()`
- Attivo quando il backend AI non è raggiungibile.
- Restituisce estratti.

## 7.4 Obiettivo
Produrre risposte basate sui documenti indicizzati, senza allucinazioni.

---

# 8. Servizi ausiliari e pipeline di supporto
ELARA include servizi supplementari che completano il flusso.

## 8.1 Sistema di hashing DocumentFile
- SHA1 per identificare versioni diverse dello stesso file.
- Consistenza tra versioni.

---

## **8.1.1 DocsIndexer**

Il servizio **DocsIndexer** è il nuovo orchestratore dell’intera pipeline di indicizzazione.  
È pensato per sostituire la logica prima contenuta dentro `IndexDocsCommand`, lasciando ai Command solo il ruolo di “interfaccia utente CLI”.

### **Funzionalità principali**
1. **Scansione directory**
   - Individua i file sotto `var/knowledge/`.
   - Supporta più path (opzione `--path` del command).
   - Applica filtri su estensioni e file ignorati.

2. **Gestione hash SHA1**
   - Calcola l’hash del file.
   - Salta quelli non modificati.
   - Supporta la modalità `forceReindex`.

3. **Pipeline completa di indicizzazione**
   - Estrazione testo → `DocumentTextExtractor`.
   - Normalizzazione.
   - Chunking → `ChunkingService`.
   - Embedding → tramite `AiClientInterface`.
   - Persistenza → Entity `DocumentFile` e `DocumentChunk`.

4. **Modalità speciali**
   - `dryRun`: nessuna scrittura, nessun embedding.
   - `testMode`: embedding finti, veloci.
   - Probes/parametri pgvector (se presenti).

5. **Callback verso i Command**
   - Notifica inizio (`onStart`).
   - Notifica avanzamento (`onFileProcessed`).
   - Produce un oggetto `IndexedFileResult` per ogni file.

### **Obiettivo**
- Centralizzare la logica.
- Aumentare testabilità.
- Rendere i Command minimalisti.

---

## 8.2 IndexDocsCommand
Esegue l’intera pipeline di indicizzazione:
1. hashing,  
2. estrazione,  
3. normalizzazione,  
4. chunking,  
5. embedding,  
6. persistenza,  
7. creazione indici.

### **(AGGIORNAMENTO)**
Dopo l’introduzione di **DocsIndexer**, il command:
- non esegue più direttamente la pipeline;
- prepara solo parametri (path, flags);
- crea ProgressBar e stampa output;
- delega tutto a `DocsIndexer::indexDirectory()`.

---

## 8.3 ListDocsCommand
Mostra:
- documenti indicizzati,
- hash,
- numero di chunk,
- data indicizzazione.

## 8.4 UnindexFileCommand
Rimuove:
- DocumentFile,
- chunk associati (cascade Doctrine).

---

# 9. Diagramma dei servizi

```
               ┌────────────────────────┐
               │        API /api/chat   │
               └──────────────┬─────────┘
                              │
                        ChatbotService
                              │
         ┌────────────────────┼────────────────────┐
         │                    │                    │
 DocumentChunkRepository   Prompt Engine        AiClientInterface
 (ricerca vettoriale)     (system+user)      (OpenAI/Ollama/Gemini)
         │                    │                    │
         └───────────────┬────┴────────────────────┘
                         │
                    PostgreSQL
                + pgvector (HNSW)

──────────────────────────────────────────────────────────────

         Indicizzazione (tramite command)

 app:index-docs
      │
      ▼
 DocsIndexer
      |
 DocumentTextExtractor
      │
 ChunkingService
      │
 AiClientInterface (embedding)
      │
 PostgreSQL + pgvector
      │
 PgvectorIvfflatMiddleware (opz.)
```
