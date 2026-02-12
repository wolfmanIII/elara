# Linee guida RAG: Dimensione Chunk e top_k per Diverse Dimensioni di Embedding

Questo documento riassume in forma ordinata e chiara le impostazioni di riferimento per:

- **dimensione massima del chunk di testo**
- **numero massimo di risultati (top_k)** nella query di cosine similarity
- valori di riferimento per differenti **dimensioni del vettore embedding**: 384, 768, 1024, 1536.

Include inoltre note operative utili per il setup self‑hosted (16GB RAM no GPU + pgvector + Ollama).

---

# 🚀 1. Principi generali

La qualità e l’efficienza del RAG dipendono soprattutto da:

- **buon chunking** (né troppo corto né troppo lungo)
- **numero appropriato di chunk restituiti** (top_k)
- **dimensione del vettore** (costo di similarity e memoria)
- **assenza di chunk "vuoti"** (problema frequente quando si spezza per caratteri fissi)

Per evitare chunk inutili ad una sola parola (es. nomi come *Malen Trast*):

- definire una **dimensione minima del chunk**
- un **overlap** del 10–20%
- merge dei paragrafi troppo brevi (< 300–400 caratteri)

---

# 📏 2. Tabella riassuntiva (valori di riferimento)

| Dimensione Vettore | Chunk max (caratteri)  | Chunk target | Chunk min | top_k |
|--------------------|------------------------|--------------|-----------|-------------------|
| **384**            | 800–1200               | ~1000        | 400       | **6**             |
| **768**            | 900–1400               | ~1200        | 400       | **5**             |
| **1024**           | 1000–1600              | ~1300–1400   | 400–500   | **4**             |
| **1536**           | 1200–1800              | ~1400–1600   | 500       | **3**             |

I valori nella colonna *target* sono quelli usati come default pratici.

---

# 🧠 3. Note specifiche per ciascuna dimensione

## 🔹 Embedding **384** (es. MiniLM)
- Veloce ma meno preciso.
- top_k impostato a **6**.
- Chunk non troppo lunghi: **1000 caratteri**.

## 🔹 Embedding **768** (es. nomic‑embed‑text)
- Punto di equilibrio per self‑hosting.
- Chunk di circa **1200 caratteri**.
- top_k impostato a **5**.

## 🔹 Embedding **1024** (es. mxbai‑embed‑large) attualmente usato in ELARA
- Qualità alta con costo computazionale maggiore.
- Chunk 1300–1400.
- top_k impostato a **4**.

## 🔹 Embedding **1536** (modelli heavy stile OpenAI|Gemini)
- Costosi in pgvector su CPU.
- Per self-hosting → top_k **3**.
- Chunk 1400–1600 caratteri.

---

# 🧩 4. Parametri di chunking di riferimento per un sistema (16GB RAM senza GPU)

Con embedding **768** + modello chat 8B (valori teorici di riferimento):

- **min_chunk**: 400 caratteri
- **target_chunk**: 1200 caratteri
- **max_chunk**: 1400 caratteri
- **overlap**: 200–300 caratteri
- **top_k**: 5
- **token totali contesto**: ~1200–1500 token

Questo bilancia qualità del retrieval e performance, evitando timeout e surriscaldamenti.

### Configurazione attuale ELARA (bge-m3, 1024 dim)

I profili in `config/packages/rag_profiles.yaml` usano valori leggermente diversi:

| Profilo | min | max | overlap | top_k |
|---------|-----|-----|---------|-------|
| ollama-bgem3 | 380 | 1200 | 250 | 5 |
| openai-mini | 380 | 1200 | 220 | 5 |
| gemini-flash | 380 | 1200 | 220 | 5 |
| offline-test | 300 | 900 | 150 | 3 |

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

# ✅ 6. Valori preconfigurati di riferimento (riassunto finale)

Per un setup self‑hosted moderno ma non estremo (16GB RAM):

- **Embedding 768** → configurazione più usata
- **Chunk target 1200**
- **Chunk max 1400**
- **top_k = 5**
- **Overlap 200–300**

Questi valori sono adatti a documenti tecnici e qualunque tipo di conoscenza

---

# 📚 7. Best practice per pgvector

## 🧩 Scelta della dimensione del vettore
- Usa sempre `VECTOR(N)` come **valore fisso** nella migration.
- Anche se modelli diversi producono dimensionalità diverse, posso:
  - **ridurre** vettori maggiori (troncamento)
  - **zero‑padding** per vettori più piccoli
- Il modo più stabile è scegliere il formato per il proprio progetto.

### Nota pratica
Per self‑hosting → **VECTOR(768)** è una configurazione equilibrata.

---

## 🚀 Ottimizzazione database pgvector

### Indici disponibili
```sql
CREATE INDEX document_chunk_embedding_hnsw
ON document_chunk USING hnsw (embedding vector_cosine_ops);
```
- HNSW offre prestazioni elevate su dataset medi (< 500k chunk).

### Quando usare IVF-Flat
- Solo se si hanno **milioni** di chunk, dataset di grandi dimensioni.
- Richiede `REINDEX` quando si aggiungono molti dati.
- Va calibrato con `lists = 100–200`.

### Vacuum & manutenzione(IVF-Flat)
```sql
VACUUM ANALYZE document_chunk;
```
- pgvector beneficia di statistiche aggiornate.

---

# 🧰 8. Algoritmo di chunking utilizzato(Servizio Symfony)

Di seguito un algoritmo di chunking che evita chunk troppo corti e include overlap:

```php
declare(strict_types=1);

namespace App\Service;

use App\Rag\RagProfileManager;
use IntlBreakIterator;

class ChunkingService
{
    /**
     * Limite assoluto di sicurezza. Nessun chunk può mai superare questo valore.
     */
    private const HARD_MAX_CHARS = 1500;

    private RagProfileManager $profiles;

    public function __construct(RagProfileManager $profiles)
    {
        $this->profiles = $profiles;
    }

    /**
     * Algoritmo di chunking moderno (Intl) che rispetta la configurazione del profilo.
     */
    public function chunkText(
        string $text,
        ?int $min = null,
        ?int $max = null,
        ?int $overlap = null
    ): array {
        // 1. Recupero configurazione
        $chunkConfig = $this->profiles->getChunking();
        $min       ??= (int) ($chunkConfig['min'] ?? 400);
        $max       ??= (int) ($chunkConfig['max'] ?? 1000);
        $overlap   ??= (int) ($chunkConfig['overlap'] ?? 200);

        // Safety check sul max per non superare mai l'hard limit
        $max = min($max, self::HARD_MAX_CHARS);

        // 2. Pulizia preliminare
        $text = $this->cleanText($text);
        if ($text === '') {
            return [];
        }

        // 3. Generazione dei chunk base (rispettando SOLO $max per ora)
        $baseChunks = $this->createBaseChunks($text, $max);

        // 4. Applicazione logica MinLen (unisce l'ultimo chunk se troppo corto)
        $baseChunks = $this->mergeLastIfTooShort($baseChunks, $min);

        // 5. Applicazione Overlap
        return $this->applyOverlap($baseChunks, $overlap);
    }

    /**
     * Crea i chunk primari usando IntlBreakIterator invece delle Regex.
     * Rispetta rigorosamente $maxLen.
     */
    private function createBaseChunks(string $text, int $maxLen): array
    {
        $iterator = IntlBreakIterator::createSentenceInstance('it_IT');
        $iterator->setText($text);

        $chunks = [];
        $currentBuffer = '';

        foreach ($iterator->getPartsIterator() as $sentence) {
            $sentenceLen = mb_strlen($sentence);

            // CASO A: La frase singola è più lunga del massimo consentito?
            // Dobbiamo spezzarla forzatamente (fallback sulle parole)
            if ($sentenceLen > $maxLen) {
                // Se c'era qualcosa nel buffer, salviamolo prima
                if ($currentBuffer !== '') {
                    $chunks[] = trim($currentBuffer);
                    $currentBuffer = '';
                }

                // Spezza la frase gigante e aggiungi i pezzi
                $subChunks = $this->splitLargeSentence($sentence, $maxLen);
                $chunks = array_merge($chunks, $subChunks);
                continue;
            }

            // CASO B: La frase ci sta nel buffer corrente?
            // Nota: +1 considererebbe lo spazio virtuale tra frasi, ma Intl lo include spesso nella frase prec.
            if (mb_strlen($currentBuffer . $sentence) <= $maxLen) {
                $currentBuffer .= $sentence;
            } else {
                // CASO C: Il buffer è pieno -> Flush
                $chunks[] = trim($currentBuffer);
                $currentBuffer = $sentence;
            }
        }

        if (trim($currentBuffer) !== '') {
            $chunks[] = trim($currentBuffer);
        }

        return $chunks;
    }

    /**
     * Se l'ultimo chunk è misero (< min), prova ad accorparlo al penultimo,
     * a patto di non sfondare HARD_MAX_CHARS.
     */
    private function mergeLastIfTooShort(array $chunks, int $minLen): array
    {
        $count = count($chunks);
        if ($count < 2) {
            return $chunks;
        }

        $lastIdx = $count - 1;
        $lastChunk = $chunks[$lastIdx];

        // Se l'ultimo chunk è già abbastanza lungo, non facciamo nulla
        if (mb_strlen($lastChunk) >= $minLen) {
            return $chunks;
        }

        // Proviamo a unire con il penultimo
        $prevIdx = $count - 2;
        $prevChunk = $chunks[$prevIdx];
        
        // Calcoliamo la lunghezza unita (aggiungendo uno spazio o newline separatore)
        $mergedText = $prevChunk . ' ' . $lastChunk;

        // Se l'unione è sicura (non supera il limite hardware), procediamo
        if (mb_strlen($mergedText) <= self::HARD_MAX_CHARS) {
            $chunks[$prevIdx] = $mergedText;
            unset($chunks[$lastIdx]);
            // Reindicizza l'array per evitare buchi negli indici
            return array_values($chunks); 
        }

        return $chunks;
    }

    /**
     * Applica l'overlap usando mb_substr (molto più veloce dello split per parole).
     * Prende la coda del chunk N-1 e la incolla in testa al chunk N.
     */
    private function applyOverlap(array $chunks, int $overlapChars): array
    {
        if ($overlapChars <= 0 || count($chunks) < 2) {
            return $chunks;
        }

        $result = [];
        $result[] = $chunks[0]; // Il primo non ha overlap

        for ($i = 1; $i < count($chunks); $i++) {
            $prevChunk = $chunks[$i - 1];
            $currChunk = $chunks[$i];

            // Calcola quanto spazio abbiamo nel chunk corrente prima di esplodere
            $currentLen = mb_strlen($currChunk);
            $availableSpace = self::HARD_MAX_CHARS - $currentLen - 1; // -1 per lo spazio

            if ($availableSpace <= 0) {
                $result[] = $currChunk;
                continue;
            }

            // L'overlap effettivo non può superare lo spazio disponibile
            $effectiveOverlap = min($overlapChars, $availableSpace);

            // Estrae la coda del precedente
            $overlapText = $this->extractCleanTail($prevChunk, $effectiveOverlap);

            if ($overlapText !== '') {
                $result[] = $overlapText . ' ' . $currChunk;
            } else {
                $result[] = $currChunk;
            }
        }

        return $result;
    }

    /**
     * Estrae gli ultimi N caratteri, ma tagliando all'inizio di una parola
     * per evitare troncatu... (troncature).
     */
    private function extractCleanTail(string $text, int $length): string
    {
        if ($length <= 0) return '';
        
        $textLen = mb_strlen($text);
        if ($textLen <= $length) return $text;

        // Prendi la sottostringa finale grezza
        $substr = mb_substr($text, -$length);

        // Cerca il primo spazio per allinearsi all'inizio di una parola
        $firstSpace = mb_strpos($substr, ' ');

        if ($firstSpace !== false) {
            return trim(mb_substr($substr, $firstSpace + 1));
        }

        // Se non trova spazi (parola lunghissima), ritorna tutto per non perdere info
        return $substr;
    }

    /**
     * Fallback per frasi giganti (usa iteratore di parole).
     */
    private function splitLargeSentence(string $sentence, int $maxLen): array
    {
        $iterator = IntlBreakIterator::createWordInstance('it_IT');
        $iterator->setText($sentence);

        $chunks = [];
        $current = '';

        foreach ($iterator->getPartsIterator() as $word) {
            if (mb_strlen($current . $word) > $maxLen) {
                if (trim($current) !== '') {
                    $chunks[] = trim($current);
                }
                $current = $word;
            } else {
                $current .= $word;
            }
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    /**
     * Pulizia Regex ottimizzata (PDF Fixes).
     */
    private function cleanText(string $text): string
    {
        // 1. Normalizza spazi
        $text = preg_replace('/\s+/u', ' ', $text);

        // 2. Fix punteggiatura attaccata
        $text = preg_replace('/(?<=[.!?;:])(?=[^\s])/u', ' ', $text);

        // 3. Fix CamelCase errato (PDF headers)
        $text = preg_replace('/([A-ZÀ-ÖØ-Ý]{2,})([A-ZÀ-ÖØ-Ý][a-zà-öø-ÿ]+)/u', '$1 $2', $text);

        // 4. Fix lowerUpper case
        $text = preg_replace('/([\p{Ll}])([\p{Lu}])/u', '$1 $2', $text);

        return trim($text ?? '');
    }
}

```

### Benefici di questo metodo
- Evita chunk “cadaveri” (solo titoletti o nomi)
- Mantiene la coerenza semantica
- Inserisce un overlap che aumenta la recall
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
- Le soglie dipendono dal modello: per embedding 768 di qualità, **0.55–0.60** è un range comune.

---

# 🧠 10. Prompt template per RAG

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

# 🗄️ 11. Uso combinato di **IVF-FLAT + HNSW**

## Panoramica
Per un sistema RAG **self‑hosted**, con **16 GB di RAM** e dataset di dimensioni medio‑piccole (documentazione, lore, manuali), usare entrambi gli indici sulla stessa colonna di embedding non aggiunge benefici misurabili.

In questo scenario si impiega un solo indice, tipicamente **HNSW**.

---

# 🧩 11.1. Differenze tra IVF-FLAT e HNSW

## 🔹 HNSW
**Usato per:** dataset piccoli/medi (fino a milioni moderati), contesti RAG.

**Pro:**
- Ottima qualità dei risultati (alta recall)
- Query veloci
- Zero tuning complesso

**Contro:**
- Indice un po’ più pesante
- Più RAM rispetto a un IVFFlat minimale

---

## 🔹 IVF-FLAT
**Impiegato su:** dataset **molto grandi** (milioni di embedding).

**Pro:**
- Scalabile su enormi volumi
- Query più leggere se configurato bene

**Contro:**
- Recall più bassa se `lists`/`probes` non sono calibrati
- Necessita tuning
- Richiede "REINDEX" dopo grandi batch di insert
- Su knowledge base medio-piccole, HNSW offre risultati più stabili con meno configurazione.

---

# 🧠 11.2. Perché NON usarli insieme

Avere **due indici** (HNSW + IVF-FLAT) sulla stessa colonna comporta:

### ❗ Problemi
- **Più spazio su disco**
- **Planner meno prevedibile** (Postgres può scegliere l'indice peggiore)
- **Build più lente**
- **Manutenzione raddoppiata**
- **Recall instabile** se IVF-FLAT non è configurato bene

### 👍 In questo contesto
- Dataset non enorme
- Self‑hosting 16GB RAM senza GPU
- Performance già buone con HNSW
- Nessuna necessità di clusterizzazione (IVF-FLAT)

👉 **Conclusione:** l'uso simultaneo non è previsto in questo contesto e aumenta costi e complessità.

---

# 🟢 11.3. Scelte operative

### Usa **solo HNSW**:
```sql
CREATE INDEX document_chunk_embedding_hnsw
ON document_chunk
USING hnsw (embedding vector_cosine_ops);
```

### Quando valutare IVF-FLAT?
Solo se:
- superi **1–2 milioni di chunk**
- e hai problemi di latenza sulla similarity
- e sei disposto a fare tuning di:
  - `lists`
  - `probes`
  - strategie di REINDEX

Negli altri casi → **HNSW** mantiene configurazione semplice e stabile.

### TL;DR finale
- **HNSW** = default usato sulla maggior parte delle knowledge base.
- **IVF-FLAT** = strumento per dataset enormi dove il tuning è accettabile.
- **Uno alla volta**: duplicare gli indici porta solo costi.

---

# 🧪 11.4. Come verificare che Postgres sta davvero usando HNSW

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
