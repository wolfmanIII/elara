# ELARA — Analisi Tecnica Completa

Questo documento fornisce una **visione tecnica completa, aggiornata ed esaustiva** del motore RAG ELARA, integrando e consolidando tutte le informazioni presenti negli altri documenti tecnici allegati.

L’obiettivo è offrire una panoramica chiara, strutturata e dettagliata dell’intera architettura: estrazione documenti, chunking, embedding, persistenza, ricerca vettoriale, pipeline di retrieval, prompt engineering, configurazioni AI e API.

---

# 📚 Indice
- [ELARA — Analisi Tecnica Completa](#elara--analisi-tecnica-completa)
- [📚 Indice](#-indice)
- [1. Panoramica Generale](#1-panoramica-generale)
- [2. Architettura del Progetto](#2-architettura-del-progetto)
- [3. Pipeline di Indicizzazione](#3-pipeline-di-indicizzazione)
    - [Passi dettagliati](#passi-dettagliati)
- [4. DocumentTextExtractor](#4-documenttextextractor)
    - [Formati supportati](#formati-supportati)
    - [Normalizzazione applicata](#normalizzazione-applicata)
    - [Obiettivo](#obiettivo)
- [5. Chunking: Strategia e Implementazione](#5-chunking-strategia-e-implementazione)
    - [Parametri consigliati](#parametri-consigliati)
- [6. Modelli di Embedding](#6-modelli-di-embedding)
    - [Dimensionalità tipiche](#dimensionalità-tipiche)
    - [Motivazione della scelta 1024](#motivazione-della-scelta-1024)
- [7. Persistenza con PostgreSQL e pgvector](#7-persistenza-con-postgresql-e-pgvector)
  - [DocumentFile](#documentfile)
  - [DocumentChunk](#documentchunk)
- [8. Indici Vettoriali: HNSW e IVF-FLAT](#8-indici-vettoriali-hnsw-e-ivf-flat)
  - [HNSW (consigliato)](#hnsw-consigliato)
  - [IVF-FLAT (solo per dataset enormi)](#ivf-flat-solo-per-dataset-enormi)
- [9. Ricerca Vettoriale e Retrieval](#9-ricerca-vettoriale-e-retrieval)
    - [Configurazioni](#configurazioni)
- [10. ChatbotService: Flusso di Generazione Risposte](#10-chatbotservice-flusso-di-generazione-risposte)
    - [Modalità speciali](#modalità-speciali)
- [11. Prompt Engineering](#11-prompt-engineering)
- [12. Backend AI: Ollama e OpenAI](#12-backend-ai-ollama-e-openai)
    - [Ollama](#ollama)
    - [OpenAI](#openai)
- [13. Controller API e Flussi REST](#13-controller-api-e-flussi-rest)
- [14. Parametri che Influenzano la Qualità delle Risposte](#14-parametri-che-influenzano-la-qualità-delle-risposte)
- [15. Command Disponibili](#15-command-disponibili)
    - [`app:index-docs`](#appindex-docs)
    - [`app:list-docs`](#applist-docs)
    - [`app:unindex-file`](#appunindex-file)
- [16. Considerazioni su Performance e Scalabilità](#16-considerazioni-su-performance-e-scalabilità)
    - [Per dataset piccoli/medi](#per-dataset-piccolimedi)
    - [Per dataset enormi (\>1M chunk)](#per-dataset-enormi-1m-chunk)
    - [Hardware](#hardware)
- [17. Conclusione Tecnica](#17-conclusione-tecnica)

---

# 1. Panoramica Generale
ELARA è un **motore RAG (Retrieval-Augmented Generation)** scritto in Symfony 7.3, che consente di:

- indicizzare documenti locali (PDF, MD, DOCX, ODT),
- estrarre il testo in modo pulito e normalizzato,
- suddividerlo in chunk ottimizzati,
- generare embedding vettoriali tramite modelli AI,
- memorizzarli in PostgreSQL tramite l’estensione pgvector,
- recuperare i chunk più rilevanti rispetto a una domanda,
- costruire un contesto da passare al modello AI (Ollama o OpenAI),
- generare una risposta basata esclusivamente sulle fonti indicizzate.

ELARA separa nettamente:
- **indicizzazione** (processo offline e batch),
- **retrieval** (runtime),
- **generazione tramite LLM**.

---

# 2. Architettura del Progetto

```
src/
  AI/              # integrazione con backend AI (OpenAI, Ollama)
  Command/         # comandi CLI per indicizzazione/lista/unindex
  Controller/      # API REST
  Entity/          # DocumentFile, DocumentChunk
  Middleware/      # ricostruzione indici vettoriali
  Repository/      # ricerca vettoriale+
  Service/         # ChatbotService, DocumentTextExtractor, ChunkingService
```

Principi chiave:
- modularità,
- astrazione del backend AI tramite AiClientInterface,
- separazione tra fase di indexing e fase di query,
- persistenza chiara, auditabile e con versioning naturale tramite DocumentFile.

---

# 3. Pipeline di Indicizzazione
Il processo segue il flusso:

**FILE → Estrattore → Chunking → Embedding → DB → Indice vettoriale → Query**

### Passi dettagliati
1. **Individuazione file** tramite `app:index-docs`.
2. **Creazione/aggiornamento DocumentFile**, con hash SHA1 per evitare duplicati.
3. **Rimozione chunk esistenti** per quel documento.
4. **Estrazione testo** tramite DocumentTextExtractor.
5. **Chunking** con regole min/max/overlap.
6. **Embedding** per ogni chunk tramite AiClientInterface.
7. **Persistenza** dei chunk con vettori.
8. **Ricostruzione indice vettoriale** (HNSW o IVF-FLAT).

---

# 4. DocumentTextExtractor
Si occupa di trasformare formati eterogenei in testo pulito e coerente.

### Formati supportati
- PDF (Smalot\PdfParser)
- Markdown
- DOCX (PhpOffice)
- ODT (ZipArchive + parsing XML)

### Normalizzazione applicata
- rimozione emoji,
- rimozione whitespace multipli,
- rimozione caratteri invisibili,
- correzione spazi mancanti (regex specializzate),
- trim globale.

### Obiettivo
Fornire un testo stabile, lineare, coerente e adatto alla fase di chunking.

---

# 5. Chunking: Strategia e Implementazione
Il chunking è uno degli elementi più critici per un RAG.
ELARA implementa un **chunking intelligente**, che:
- evita chunk troppo corti,
- rispetta un limite massimo di sicurezza (hard max 1500 chars),
- costruisce chunk tramite frasi e parole,
- aggiunge overlap tra chunk basato su numero di caratteri,
- unisce l’ultimo chunk se troppo corto.

### Parametri consigliati
- `min`: 400–500
- `target`: 1200–1600
- `max`: 1500–1800
- `HARD_MAX_CHARS`: 1500
- `overlap`: 200–300

---

# 6. Modelli di Embedding
Gli embedding rappresentano il significato del testo come vettori.

ELARA supporta qualsiasi modello embedding via AiClientInterface.

### Dimensionalità tipiche
- **384** – leggero, meno preciso
- **768** – bilanciato
- **1024** – alta qualità (es. bge-m3)
- **1536** – qualità massima per sistemi più potenti

### Motivazione della scelta 1024
Il modello nomic-text-embed presenta bug noti su Ollama; bge-m3 produce embedding multidimensionali stabili a 1024.

---

# 7. Persistenza con PostgreSQL e pgvector
I chunk vengono memorizzati in due tabelle:

## DocumentFile
- UUID
- path
- hash
- createdAt
- relazione OneToMany verso chunk

## DocumentChunk
- UUID
- indice
- contenuto
- embedding `vector(1024)`
- relazione ManyToOne verso DocumentFile

---

# 8. Indici Vettoriali: HNSW e IVF-FLAT
Due possibili indici vettoriali, entrambi supportati da pgvector.

## HNSW (consigliato)
- Alta precisione
- Ottime performance
- Zero tuning

## IVF-FLAT (solo per dataset enormi)
- Richiede tuning (lists/probes)
- Necessita REINDEX dopo grandi batch
- Recall più bassa se configurato male

ELARA supporta entrambi ma **sconsiglia vivamente l’uso simultaneo**.

---

# 9. Ricerca Vettoriale e Retrieval
Il retrieval avviene ordinando i chunk per similarità cosine:

```
ORDER BY embedding <=> :query_vec
```

### Configurazioni
- top_k variabile in base al modello embedding:
  - 384 → 6
  - 768 → 5
  - 1024 → 4
  - 1536 → 3

- soglia di similarità tipica: `> 0.55`

Risultati più simili costituiscono il **contesto** per la fase di generazione.

---

# 10. ChatbotService: Flusso di Generazione Risposte
Funzione `ask()`:
1. embed della domanda
2. ricerca vettoriale con top_k
3. costruzione contesto RAG
4. generazione prompt
5. chiamata al backend AI
6. fallback in caso di errore

### Modalità speciali
- **test mode** → ricerca testuale LIKE sui chunk
- **offline fallback** → estratti di testo se l’AI è indisponibile

---

# 11. Prompt Engineering
Il prompt del system è fondamentale:

```
Rispondi SOLO usando il contesto. Non inventare.
```

Il template RAG:
```
# CONTEXT
{{context}}

# DOMANDA
{{question}}
```

Questo riduce hallucination e mantiene coerenza.

---

# 12. Backend AI: Ollama e OpenAI
ELARA permette di cambiare backend tramite ENV:

```
AI_BACKEND=ollama|openai
```

### Ollama
- locale
- nessun costo
- latenza maggiore

### OpenAI
- qualità superiore
- costi per token

Modelli chat consigliati: 7B–8B per uso locale.

---

# 13. Controller API e Flussi REST
Endpoint principale:

```
POST /api/chat
{
  "message": "domanda"
}
```

Flusso:
Client → Controller → ChatbotService → AI → Risposta JSON.

Errori gestiti:
- messaggio vuoto
- backend indisponibile
- fallback

---

# 14. Parametri che Influenzano la Qualità delle Risposte
Tutti i dettagli tecnici sono riportati nel documento dedicato, ma riportiamo i punti chiave:

- modello embedding
- modello chat
- chunking (min/max/overlap)
- top_k
- soglia cosine
- indice vettoriale (HNSW consigliato)
- system prompt
- qualità del testo estratto

---

# 15. Command Disponibili
### `app:index-docs`
Indicizza o reindicizza documenti.

### `app:list-docs`
Mostra documenti indicizzati, hash, numero chunk.

### `app:unindex-file`
Rimuove un documento e i suoi chunk.

---

# 16. Considerazioni su Performance e Scalabilità
### Per dataset piccoli/medi
- HNSW è perfetto
- embedding 768–1024
- modelli chat 7B–8B

### Per dataset enormi (>1M chunk)
- considerare IVF-FLAT con tuning
- ridurre dimensionalità vettori
- limitare overlap

### Hardware
- CPU-only → evitare modelli troppo grandi
- 16 GB RAM → limite operativo ideale 1024-dim + chat 7B

---

# 17. Conclusione Tecnica
ELARA è un motore RAG completo, modulare, estensibile, interamente self-hosted, compatibile con PostgreSQL/pgvector e backend AI intercambiabili.

La struttura chiara e separata in fasi garantisce:
- controllo totale del flusso,
- prestazioni prevedibili,
- qualità delle risposte basata sui documenti,
- facilità di manutenzione e scaling.

---
