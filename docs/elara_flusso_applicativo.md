# ELARA — Flusso Applicativo Completo e Aggiornato

L’obiettivo è offrire una descrizione chiara, completa e strutturata del **flusso applicativo reale di ELARA**, dal caricamento dei documenti fino alla generazione della risposta.

---

# 📚 Indice
- [ELARA — Flusso Applicativo Completo e Aggiornato](#elara--flusso-applicativo-completo-e-aggiornato)
- [📚 Indice](#-indice)
- [1. Panoramica generale](#1-panoramica-generale)
- [2. Schema del flusso applicativo](#2-schema-del-flusso-applicativo)
- [3. Fase 1 — Acquisizione e gestione dei file](#3-fase-1--acquisizione-e-gestione-dei-file)
- [4. Fase 2 — Estrazione e normalizzazione del testo](#4-fase-2--estrazione-e-normalizzazione-del-testo)
  - [Formati supportati](#formati-supportati)
  - [Normalizzazioni applicate](#normalizzazioni-applicate)
- [5. Fase 3 — Chunking avanzato](#5-fase-3--chunking-avanzato)
    - [Caratteristiche principali](#caratteristiche-principali)
    - [Parametri consigliati](#parametri-consigliati)
- [6. Fase 4 — Generazione embedding](#6-fase-4--generazione-embedding)
- [7. Fase 5 — Persistenza e struttura dati](#7-fase-5--persistenza-e-struttura-dati)
  - [DocumentFile](#documentfile)
  - [DocumentChunk](#documentchunk)
- [8. Fase 6 — Costruzione o aggiornamento dell’indice vettoriale](#8-fase-6--costruzione-o-aggiornamento-dellindice-vettoriale)
  - [✔ HNSW (consigliato)](#-hnsw-consigliato)
  - [✔ IVF-FLAT (solo dataset enormi)](#-ivf-flat-solo-dataset-enormi)
- [9. Fase 7 — Workflow API /api/chat](#9-fase-7--workflow-api-apichat)
- [10. Fase 8 — Retrieval e costruzione del contesto](#10-fase-8--retrieval-e-costruzione-del-contesto)
    - [Obiettivo](#obiettivo)
- [11. Fase 9 — Generazione della risposta tramite LLM](#11-fase-9--generazione-della-risposta-tramite-llm)
- [12. Fallback: Test mode \& Offline mode](#12-fallback-test-mode--offline-mode)
  - [Test mode](#test-mode)
  - [Offline fallback](#offline-fallback)
- [13. Flusso completo riassunto](#13-flusso-completo-riassunto)
- [14. Conclusione](#14-conclusione)

---

# 1. Panoramica generale
ELARA implementa un sistema completo di **Retrieval-Augmented Generation (RAG)**, in cui:
- i documenti vengono indicizzati in forma strutturata,
- le informazioni vengono trasformate in embedding vettoriali,
- PostgreSQL + pgvector effettua ricerche semantiche,
- un modello LLM (Ollama o OpenAI) genera risposte basate sui documenti.

L’obiettivo è garantire risposte **accurate, contestualizzate e riproducibili**, senza allucinazioni.

---

# 2. Schema del flusso applicativo

```
FILE → Estrattore → Chunking → Embedding → PostgreSQL
      → Indice Vettoriale (HNSW/IVF-FLAT)
      → /api/chat → embedding domanda → ricerca vettoriale
      → contesto → prompt → modello AI → risposta → JSON
```

---

# 3. Fase 1 — Acquisizione e gestione dei file
La fase è gestita dal comando:
```
app:index-docs <path>
```

Per ogni file:
1. calcolo dell’hash SHA1,
2. creazione o aggiornamento di **DocumentFile**,
3. rimozione chunk precedenti (garanzia di consistenza),
4. passaggio all’estrazione del testo.

Questa fase garantisce
- idempotenza,
- gestione di file aggiornati,
- nessun duplicato.

---

# 4. Fase 2 — Estrazione e normalizzazione del testo
Gestita da **DocumentTextExtractor**.

## Formati supportati
- PDF,
- Markdown,
- DOCX,
- ODT.

## Normalizzazioni applicate
- rimozione emoji, dingbats e simboli non informativi,
- rimozione whitespace multipli,
- rimozione caratteri invisibili,
- correzione degli spazi mancanti dopo segni di punteggiatura,
- correzione separazione MAIUSCOLO+Capitalized (es. "RUOLORisorse" → "RUOLO Risorse"),
- trim globale.

Questa fase produce un testo ideale per il chunking.

---

# 5. Fase 3 — Chunking avanzato
Implementato dal **ChunkingService**.

### Caratteristiche principali
- uso di frasi e parole come unità logiche,
- rispetto limite assoluto HARD_MAX_CHARS (1500),
- fusione chunk finali troppo corti,
- overlap basato sulle ultime parole del chunk precedente,
- divisione ragionata dei paragrafi.

### Parametri consigliati
- `min`: 400–500 caratteri
- `target`: 1200–1600 caratteri
- `max`: 1500–1800 caratteri
- `overlap`: 200–300 caratteri

L’obiettivo: chunk coerenti, completi e ben indicizzabili.

---

# 6. Fase 4 — Generazione embedding
Ogni chunk viene trasformato in un vettore float via AiClientInterface.

Modelli supportati:
- OpenAI embedding (1536 dim),
- Ollama embedding (bge-m3, 1024 dim),
- ogni altro modello conforme allo standard.

La dimensionalità vettoriale è dichiarata nel database come:
```
VECTOR(1024)
```
(consigliato, stabile e compatibile con bge-m3).

---

# 7. Fase 5 — Persistenza e struttura dati
I chunk e i file vengono memorizzati tramite Doctrine.

## DocumentFile
- UUID
- path
- hash
- createdAt
- relazione OneToMany → chunk

## DocumentChunk
- UUID
- chunkIndex
- contenuto
- embedding vettoriale
- relazione ManyToOne → DocumentFile

---

# 8. Fase 6 — Costruzione o aggiornamento dell’indice vettoriale
ELARA supporta entrambi gli indici disponibili in pgvector:

## ✔ HNSW (consigliato)
- più preciso
- più semplice
- più veloce

## ✔ IVF-FLAT (solo dataset enormi)
- richiede tuning `lists` e `probes`
- richiede REINDEX
- meno preciso se configurato male

Il middleware **PgvectorIvfflatMiddleware** si occupa della ricostruzione automatica (quando configurato).

---

# 9. Fase 7 — Workflow API /api/chat
L’API principale è:
```
POST /api/chat
{
  "message": "domanda utente"
}
```

Flusso:
1. validazione input,
2. chiamata al ChatbotService,
3. gestione eccezioni,
4. generazione risposta JSON.

---

# 10. Fase 8 — Retrieval e costruzione del contesto
ChatbotService esegue:
1. embedding della domanda,
2. ricerca tramite cosine distance:
   ```
   ORDER BY embedding <=> :query_vec
   ```
3. applicazione `top_k` (tipicamente 3–6 in base al modello embedding),
4. eventuale soglia minima di similarità (es. > 0.55),
5. costruzione del **contesto RAG**, concatenando i chunk.

### Obiettivo
Recuperare solo i chunk semanticamente più rilevanti.

---

# 11. Fase 9 — Generazione della risposta tramite LLM
ChatbotService genera un prompt del tipo:

```
Rispondi SOLO usando il contesto sotto. Non inventare.

# CONTEXT
{{context}}

# DOMANDA
{{question}}
```

Il backend AI (Ollama o OpenAI) produce la risposta, che viene restituita in formato JSON.

---

# 12. Fallback: Test mode & Offline mode
ELARA supporta due modalità operative aggiuntive.

## Test mode
Attivabile via ENV:
```
APP_AI_TEST_MODE=true
```
Il sistema:
- non chiama l’AI,
- cerca chunk usando LIKE,
- restituisce estratti utili per diagnosticare il retrieval.

## Offline fallback
```
APP_AI_OFFLINE_FALLBACK=true
```
Se il backend AI fallisce, ELARA:
- recupera chunk rilevanti,
- restituisce un riassunto non generato.

---

# 13. Flusso completo riassunto

```
app:index-docs
 → hashed file
 → estrazione testo
 → normalizzazione
 → chunking
 → embedding
 → salvataggio
 → indice vettoriale

/api/chat
 → embedding domanda
 → ricerca vettoriale
 → contesto
 → prompt RAG
 → AI backend
 → risposta JSON
```

---

# 14. Conclusione
Il flusso applicativo di ELARA è progettato per essere:
- **robusto**, grazie a fasi chiaramente separate,
- **scalabile**, tramite pgvector e indici personalizzabili,
- **affidabile**, grazie a fallback e test mode,
- **estendibile**, grazie alla modularità dei servizi AI,
- **deterministico**, poiché risponde solo usando i documenti indicizzati.

Questo documento rappresenta la versione completa e consolidata dell’intero flusso applicativo, pronta per essere utilizzata come riferimento principale nella documentazione tecnica di ELARA.

---
