# Linee guida RAG: Dimensione Chunk e top_k per Diverse Dimensioni di Embedding

Questo documento riassume in forma ordinata e chiara le raccomandazioni per:

- **dimensione massima del chunk di testo**
- **numero massimo di risultati (top_k)** nella query di cosine similarity
- valori consigliati per differenti **dimensioni del vettore embedding**: 384, 768, 1024, 1536.

Include inoltre note operative utili per il tuo setup self‑hosted (WSL2 + 16GB RAM + pgvector + Ollama).

---

# 🚀 1. Principi generali

La qualità e l’efficienza del RAG dipendono soprattutto da:

- **buon chunking** (né troppo corto né troppo lungo)
- **numero appropriato di chunk restituiti** (top_k)
- **dimensione del vettore** (costo di similarity e memoria)
- **assenza di chunk "vuoti"** (problema frequente quando si spezza per caratteri fissi)

Per evitare chunk inutili ad una sola parola (es. nomi come *Malen Trast*), si consiglia:

- definire una **dimensione minima del chunk**
- un **overlap** del 10–20%
- merge dei paragrafi troppo brevi (< 300–400 caratteri)

---

# 📏 2. Tabella riassuntiva (valori consigliati)

| Dimensione Vettore | Chunk max (caratteri) | Chunk target | Chunk min | top_k consigliato |
|--------------------|------------------------|--------------|-----------|-------------------|
| **384**            | 800–1200               | ~1000        | 400       | **6**             |
| **768**            | 900–1400               | ~1200        | 400       | **5**             |
| **1024**           | 1000–1600              | ~1300–1400   | 400–500   | **4**             |
| **1536**           | 1200–1800              | ~1400–1600   | 500       | **3**             |

I valori nella colonna *target* sono quelli consigliati come default pratici.

---

# 🧠 3. Note specifiche per ciascuna dimensione

## 🔹 Embedding **384** (es. MiniLM)
- Molto veloce ma meno preciso.
- Meglio aumentare leggermente top_k → **6**.
- Chunk non troppo lunghi: **1000 caratteri** è un ottimo valore.

## 🔹 Embedding **768** (es. nomic‑embed‑text)
- Punto di equilibrio perfetto per self‑hosting.
- Chunk più robusti: **1200 caratteri**.
- top_k ideale: **5**.

## 🔹 Embedding **1024** (es. mxbai‑embed‑large)
- Ottima qualità, costo computazionale maggiore.
- Chunk un po’ più ampi (1300–1400).
- top_k ridotto a **4**.

## 🔹 Embedding **1536** (modelli heavy stile OpenAI)
- Costosissimi in pgvector su CPU.
- Per self-hosting → top_k basso (**3**).
- Chunk ampi: 1400–1600 caratteri.

---

# 🧩 4. Parametri di chunking consigliati per un sistema (WSL2 + 16GB RAM)

Con embedding **768** + modello chat 8B:

- **min_chunk**: 400 caratteri
- **target_chunk**: 1200 caratteri
- **max_chunk**: 1400 caratteri
- **overlap**: 200–300 caratteri
- **top_k**: 5
- **token totali contesto**: ~1200–1500 token

Questo bilancia qualità del retrieval e performance, evitando timeout e surriscaldamenti.  
>*per problemi del modello nomic-text-embed per Ollama 0.13.1(latest), sono stato costretto ad usare il modello bge-m3 che utilizza vettori a 1024*

---

# 🛠️ 5. Considerazioni aggiuntive

### ✓ Evitare chunk "quasi vuoti"
- Se un paragrafo è troppo corto (<300–400 caratteri), va unito al precedente/successivo.
- Se spezzetti a misura fissa, si aggiunge un overlap.

### ✓ Ridurre il rumore nelle query
top_k troppo alto genera contesto troppo esteso, lento e dispersivo.

### ✓ Bilanciare i token per l’LLM
I modelli 7B/8B in self-hosting non amano contesti da 2500+ token.

---

# ✅ 6. Valori preconfigurati consigliati (riassunto finale)

Per un setup self‑hosted moderno ma non estremo (16GB RAM):

- **Embedding 768** → *scelta raccomandata*
- **Chunk target 1200**
- **Chunk max 1400**
- **top_k = 5**
- **Overlap 200–300**

Questi valori sono adatti a documenti tecnici e qualunque tipo di conoscenza

---

# 📚 7. Best practice per pgvector

## 🧩 Scelta della dimensione del vettore
- Usa sempre `VECTOR(N)` come **valore fisso** nella migration.
- Anche se modelli diversi producono dimensionalità diverse, puoi:
  - **ridurre** vettori maggiori (troncamento)
  - **zero‑padding** per vettori più piccoli
- Il modo più stabile è scegliere il formato per il proprio progetto.

### Consiglio pratico
Per self‑hosting → **VECTOR(768)** è la scelta più equilibrata.

---

## 🚀 Ottimizzazione database pgvector

### Indici raccomandati
```sql
CREATE INDEX document_chunk_embedding_hnsw
ON document_chunk USING hnsw (embedding vector_cosine_ops);
```
- HNSW è molto più veloce dell’IVFFlat, specialmente su dataset medi (< 500k chunk).

### Quando usare IVF Flat
- Solo se hanno **milioni** di chunk.
- Richiede `REINDEX` quando si aggiungono molti dati.
- Va calibrato con `lists = 100–200`.

### Vacuum & manutenzione
```sql
VACUUM ANALYZE document_chunk;
```
- pgvector beneficia di statistiche aggiornate.

---

# 🧰 8. Funzione di chunking utilizzata

Di seguito un algoritmo di chunking che evita chunk troppo corti e include overlap:

```php
function chunkText(string $text, int $min = 400, int $target = 1200, int $max = 1400, int $overlap = 250): array
{
    $parts = preg_split('/
{2,}/', $text); // Splitta per paragrafi
    $chunks = [];
    $buffer = '';

    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;

        // Se il paragrafo è troppo corto → merge
        if (strlen($p) < $min) {
            $buffer .= ($buffer ? "
" : '') . $p;
            continue;
        }

        // Se buffer + paragrafo supera max → chiudi chunk
        if (strlen($buffer) + strlen($p) > $max) {
            if ($buffer !== '') $chunks[] = $buffer;
            $buffer = $p;
            continue;
        }

        // Aggiungi al buffer
        $buffer .= ($buffer ? "
" : '') . $p;

        // Se raggiungiamo il target → chiudiamo il chunk
        if (strlen($buffer) >= $target) {
            $chunks[] = $buffer;
            $buffer = '';
        }
    }

    if ($buffer !== '') $chunks[] = $buffer;

    // Aggiungi overlap
    $final = [];
    for ($i = 0; $i < count($chunks); $i++) {
        $chunk = $chunks[$i];
        if ($i > 0) {
            $prev = $chunks[$i - 1];
            $chunk = substr($prev, -$overlap) . "
" . $chunk;
        }
        $final[] = $chunk;
    }

    return $final;
}
```

### Benefici di questo metodo
- Evita chunk “cadaveri” (solo titoletti o nomi)
- Mantiene la coerenza semantica
- Inserisce un overlap che migliora drasticamente la recall
- Produce chunk di lunghezza prevedibile

---

# 🔎 9. Esempi di query SQL pgvector ottimizzate

## Cosine similarity standard
```sql
SELECT id, path, text,
       1 - (embedding <=> :query_vec) AS score
FROM document_chunk
ORDER BY embedding <=> :query_vec
LIMIT 5;
```

## Con filtraggio per documento
```sql
SELECT id, text
FROM document_chunk
WHERE path = :doc
ORDER BY embedding <=> :q
LIMIT :k;
```

## Con soglia minima di score
```sql
SELECT *, 1 - (embedding <=> :v) AS score
FROM document_chunk
WHERE (1 - (embedding <=> :v)) > 0.55
ORDER BY embedding <=> :v
LIMIT 5;
```

### Nota
- Le soglie sono sensibili al modello: per embedding 768 di qualità, **0.55–0.60** è un buon range.

---

# 🧠 10. Prompt template consigliato per RAG

```text
Sei un assistente e DEVI rispondere esclusivamente usando il contesto sotto.
Se la risposta non è presente nel contesto, di' che non è disponibile.

# CONTEX
{{context}}

# DOMANDA
{{question}}

Rispondi in modo chiaro e sintetico nella lingua dell'utente.
```

### Perché funziona bene
- Evita hallucination
- Costringe l’LLM a usare i chunk
- Funziona bene con modelli 7B/8B self‑hosted

---

# 🗄️ 11. È utile usare **IVFFlat + HNSW insieme?**

## ❌ Risposta breve
Per un sistema RAG **self‑hosted**, con **16 GB di RAM** e dataset di dimensioni medio‑piccole (documentazione, lore, manuali), **NO**: usare **entrambi gli indici** sulla stessa colonna di embedding **non è né necessario né utile**.

Un solo indice — **HNSW** — è la scelta corretta nel 99% dei casi.

---

# 🧩 11.1. Differenze tra IVFFlat e HNSW

## 🔹 HNSW
**Ideale per:** dataset piccoli/medi (fino a milioni moderati), contesti RAG.

**Pro:**
- Ottima qualità dei risultati (alta recall)
- Query veloci
- Zero tuning complesso

**Contro:**
- Indice un po’ più pesante
- Più RAM rispetto a un IVFFlat minimale

---

## 🔹 IVFFlat
**Ideale per:** dataset **molto grandi** (milioni di embedding).

**Pro:**
- Scalabile su enormi volumi
- Query più leggere se configurato bene

**Contro:**
- Recall più bassa se `lists`/`probes` non sono calibrati
- Necessita tuning
- Richiede "REINDEX" dopo grandi batch di insert

---

# 🧠 11.2. Perché NON usarli insieme

Avere **due indici** (HNSW + IVFFlat) sulla stessa colonna comporta:

### ❗ Problemi
- **Più spazio su disco**
- **Planner meno prevedibile** (Postgres può scegliere l'indice peggiore)
- **Build più lente**
- **Manutenzione raddoppiata**
- **Recall instabile** se IVFFlat non è configurato bene

### 👍 In questo contesto
- Dataset non enorme
- Self‑hosting in WSL2
- Performance già buone con HNSW
- Nessuna necessità di clusterizzazione (IVFFlat)

👉 **Conclusione:** usare entrambi è overkill e rischia di peggiorare la qualità.

---

# 🟢 11.3. Raccomandazione ufficiale per il tuo progetto

### Usa **solo HNSW**:
```sql
CREATE INDEX document_chunk_embedding_hnsw
ON document_chunk
USING hnsw (embedding vector_cosine_ops);
```

### Quando considerare IVFFlat?
Solo se:
- superi **1–2 milioni di chunk**
- e hai problemi di latenza sulla similarity
- e sei disposto a fare tuning di:
  - `lists`
  - `probes`
  - strategie di REINDEX

In qualunque altro caso → **HNSW è migliore, più semplice e più affidabile**.

---

# 🧪 11.4. Come verificare che Postgres sta davvero HNSW

```sql
EXPLAIN ANALYZE
SELECT id, text
FROM document_chunk
ORDER BY embedding <=> :q
LIMIT 5;
```

Si dovrebbe vedere qualcosa come:
```
Index Scan using document_chunk_embedding_hnsw on document_chunk
```
Se invece si vede "Seq Scan" → manca l'indice o Postgres non lo ritiene conveniente.

---

# 🔚 Fine documento

