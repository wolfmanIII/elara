# ELARA — Analisi Tecnica Completa

Questo documento fornisce una **visione tecnica completa, aggiornata ed esaustiva** del motore RAG ELARA

L’obiettivo è offrire una panoramica chiara, strutturata e dettagliata dell’intera architettura: estrazione documenti, chunking, embedding, persistenza, ricerca vettoriale, pipeline di retrieval, prompt engineering, configurazioni AI e API.

---

# 1. Panoramica Generale
ELARA è un **motore RAG (Retrieval-Augmented Generation)** scritto in Symfony 7.3, che consente di:

- indicizzare documenti locali (PDF, MD, DOCX, ODT),
- estrarre il testo in modo pulito e normalizzato,
- suddividerlo in chunk ottimizzati,
- generare embedding vettoriali tramite modelli AI,
- memorizzarli in PostgreSQL tramite l’estensione pgvector,
- recuperare i chunk più rilevanti rispetto a una domanda,
- costruire un contesto da passare al modello AI (Ollama, OpenAI, Gemini),
- generare una risposta basata esclusivamente sulle fonti indicizzate.

ELARA separa nettamente:
- **indicizzazione** (processo offline e batch),
- **retrieval** (runtime),
- **generazione tramite LLM**.

---

# 2. Architettura del Progetto

Struttura principale delle sorgenti:

```
src/
  AI/                 # client per Ollama, OpenAI, Gemini
  Command/            # CLI (index, list, reset schema, API token…)
  Controller/         # dashboard, API REST, profili RAG
  Entity/             # DocumentFile, DocumentChunk, ApiToken, User
  Middleware/         # supporto pgvector (IVF-FLAT probes)
  Rag/                # RagProfileManager + storage profilo attivo
  Repository/         # query vettoriali ottimizzate
  Security/           # autenticazione via API token
  Service/            # DocsIndexer, ChunkingService, ChatbotService, estrazione testo
  Twig/               # componenti UX (modali Markdown, badge, ecc.)

templates/            # UI Tailwind/Daisy, include modali markdown dinamiche
assets/               # Stimulus + JS (es. rag_profile_switch_controller)
docs/                 # documentazione ufficiale (mirrored anche in var/knowledge)
var/knowledge/        # knowledge base indicizzabile, usata dall’indexer
```

Gli elementi recenti includono:
- gestione profili RAG lato UI con pulsanti inline e controller Stimulus per evitare click doppi;
- modali Markdown riutilizzabili per consultare la documentazione senza lasciare la dashboard;
- mirror automatico dei documenti in `var/knowledge/` così l’indexer legge le stesse fonti mostrate in UI.

Principi chiave:
- modularità,
- astrazione del backend AI tramite AiClientInterface e profili,
- separazione tra fase di indexing e fase di query,
- persistenza chiara, auditabile e con versioning naturale tramite DocumentFile,
- documentazione sempre disponibile direttamente nella UI per guidare l’operatore.

## 2.1 Gestione Profili RAG da UI
- Pagina `Status → RAG Profiles` elenca i preset con badge “Attivo” e pulsante “Attiva” inline.
- Ogni pulsante invia un form dedicato (`rag_profile_switch_controller`) che disabilita tutti i bottoni finché lo switch non termina, evitando click multipli.
- Quando il profilo richiede una dimensione embedding diversa da quella attualmente salvata in `DocumentChunk->embedding`, viene mostrato automaticamente un alert con la dimensione mancante e i due comandi da eseguire (`reset-rag-schema`, `index-docs --force-reindex`).
- La sezione include link rapidi a tutta la documentazione (modali Markdown) così l’operatore può verificare chunking, parametri e guide senza uscire dall’app.
- `RagProfileManager` centralizza i preset (`config/packages/rag_profiles.yaml`), persiste la scelta corrente (`ActiveProfileStorage`) e fornisce a runtime i parametri di chunking, retrieval (`top_k`, `min_score`) e AI. ChatbotService e DocumentChunkRepository leggono da qui per restare allineati al profilo attivo.

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
- size
- indexedAt
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

## HNSW (ideale per dataset di piccole medie dimensioni)
- Alta precisione
- Ottime performance
- Zero tuning

## IVF-FLAT (solo per dataset di grandi dimensioni)
- Richiede tuning (lists/probes)
- Necessita REINDEX dopo grandi batch
- Recall più bassa se configurato male

ELARA supporta entrambi ma **si sconsiglia vivamente l’uso simultaneo**.

> **Linea guida rapida:** se non stai gestendo un grafo di milioni di chunk, resta su HNSW. IVF-FLAT diventa utile solo su collezioni gigantesche in cui vale la pena pagare tuning e manutenzione.

### Riepilogo flash
- **Default:** HNSW per dataset piccoli/medi e per la maggior parte delle knowledge base.
- **Solo eccezione:** IVF-FLAT quando i chunk superano i milioni e servono indici più leggeri da calibrare.
- **Mai insieme:** un solo indice attivo evita sprechi e incoerenze.

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

Di seguito un articolo molto esplicativo sui concetti espressi nella ricerca vettoriale, come:
Cosine Similarty, Approximate-Nearest Neighbor(ANN)  
[IBM ricerca vettoriale](https://www.ibm.com/it-it/think/topics/vector-search)

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

# 12. Backend AI: Ollama - OpenAI - Gemini
ELARA permette di cambiare backend tramite i preset RAG (`config/packages/rag_profiles.yaml`) selezionati con `RAG_PROFILE=<nome>` o con il flag CLI `--rag-profile=<nome>`.

### Ollama
- locale
- nessun costo
- latenza maggiore

### OpenAI | Gemini
- qualità superiore
- costi per token

Modelli chat consigliati: 7B–8B per uso locale.

---

# 13. Controller API e Flussi REST
Endpoint principale:

```
POST /api/chat
{
  "question": "domanda"
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
