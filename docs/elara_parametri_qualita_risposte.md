# Parametri che Influenzano la Qualità delle Risposte

Questo documento raccoglie in un'unica sede tutti i parametri di configurazione che incidono direttamente sulla **qualità delle risposte** del motore RAG.

---

# 1. Introduzione
La qualità della risposta generata da un motore RAG dipende dall’interazione di più componenti: il modello AI, il chunking, l’indice vettoriale, la pipeline del retrieval e la qualità dell’estrazione del testo.

Questo documento descrive nel dettaglio **tutti i parametri che influenzano direttamente questa qualità**.

---

# 2. Parametri del Backend AI

## 2.1 Modello di embedding
La scelta del modello determina la qualità del retrieval. Modelli con embedding più ampi catturano meglio le sfumature semantiche, a scapito delle performance.

Dimensioni embedding analizzate:
- 384 → leggero, meno preciso
- 768 → ottimo bilanciamento
- 1024 → alta qualità (es. bge‑m3)
- 1536 → qualità massima ma più pesante

## 2.2 Modello di chat
Influisce sulla qualità linguistica e sul ragionamento. Modelli più grandi (7B, 8B, 13B) garantiscono risposte più coerenti e robuste.

## 2.3 Backend selezionato
Oggi la selezione avviene tramite i preset dichiarati in `config/packages/rag_profiles.yaml` (variabile `RAG_PROFILE=<nome>`). Ogni profilo definisce:
- backend (`ollama|openai|gemini`)
- modelli chat/embedding
- dimensioni embedding
- flag test/fallback

OpenAI → qualità più alta.  
Gemini → qualità più alta.  
Ollama → locale, meno costo, più latenza.

---

# 3. Parametri di Chunking
Il chunking ha impatto diretto sulla qualità del contesto fornito all’AI.

## 3.1 Valori di riferimento
- **Chunk min:** 400–500 caratteri
- **Chunk target:** 1200–1600 caratteri
- **Chunk max:** 1500–1800 caratteri
- **Hard limit:** 1500 caratteri
- **Overlap:** 200–300 caratteri

## 3.2 Effetti sulla qualità
- Chunk troppo corti → contesto povero, embedding imprecisi
- Chunk troppo lunghi → meno risultati pertinenti
- Chunk calibrati → miglior recall e contesto più coerente

---

# 4. Parametri della Ricerca Vettoriale (Retrieval)

## 4.1 top_k
Numero di chunk recuperati per la costruzione del contesto.

Valori di riferimento:
- embedding 384 → top_k **6**
- embedding 768 → top_k **5**
- embedding 1024 → top_k **4**
- embedding 1536 → top_k **3**

## 4.2 Soglia di similarità
Esempio:
```
WHERE (1 - (embedding <=> :v)) > 0.55
```
Permette di escludere chunk poco rilevanti.

## 4.3 Indice vettoriale
Configurazione in uso:
### ✔ HNSW (default attivo)
- Ottima qualità dei risultati
- Alta velocità
- Nessun tuning necessario

### ❌ IVF‑Flat solo per database enormi
- Necessita tuning (lists, probes)
- Richiede REINDEX
- Meno accurato se non calibrato

Non usare entrambi simultaneamente.

---

# 5. Parametri del Prompt e del ChatbotService

## 5.1 System prompt
Istruzione fondamentale:
```
Rispondi SOLO usando il contesto. Non inventare.
```
Riduce il rischio di hallucination.

## 5.2 Template RAG
```
# CONTEXT
{{context}}

# DOMANDA
{{question}}
```
Chiaro, robusto e aderente ai documenti.

## 5.3 Modalità operative
Queste modalità sono configurate dentro ogni profilo RAG (`ai.test_mode`, `ai.offline_fallback`).  
Posso comunque override locale con:
```
APP_AI_TEST_MODE=true|false
APP_AI_OFFLINE_FALLBACK=true|false
```
Determinano se il chatbot usa la modalità test (solo estratti) o il fallback locale in caso di errore del backend.

---

# 6. Parametri di Qualità dei Documenti Indicizzati
La qualità del testo influisce sulla qualità degli embedding.

## 6.1 Normalizzazione
Il DocumentTextExtractor applica:
- rimozione whitespace multipli
- rimozione caratteri invisibili
- correzione spazi mancanti
- rimozione emoji

## 6.2 Coerenza dell’indice
Durante la reindicizzazione:
- tutti i chunk precedenti vengono rimossi per evitare dati obsoleti

Chunk puliti → embedding di qualità.

---

# 7. Riepilogo Finale

| Categoria | Parametri chiave |
|----------|-------------------|
| **Backend AI** | modello embedding, modello chat, RAG_PROFILE (preset) |
| **Chunking** | min, target, max, overlap, HARD_MAX_CHARS |
| **Retrieval** | top_k, soglia cosine, indice HNSW, probes IVF-FLAT |
| **Prompt** | system prompt, RAG template, modalità test/fallback |
| **Qualità documenti** | normalizzazione testo, rimozione emoji, coerenza dei chunk |

Tutti questi parametri lavorano insieme: migliorare uno solo non basta. L’ottimizzazione va sempre considerata nel suo insieme.

---
