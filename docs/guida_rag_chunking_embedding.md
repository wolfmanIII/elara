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

class ChunkingService
{
    /**
     * Limite assoluto di sicurezza sulla lunghezza di un chunk (in caratteri).
     * Serve ad evitare di mandare a Ollama input troppo lunghi, che possono
     * generare errori tipo:
     *
     *   "panic: caching disabled but unable to fit entire input in a batch"
     */
    private const HARD_MAX_CHARS = 1500;

    /**
     * Algoritmo di chunking:
     * - sistema alcuni spazi mancanti (da PDF/OCR) con fixMissingSpaces()
     * - splitta per paragrafi (2+ newline consecutivi)
     * - per ogni paragrafo crea chunk usando frasi/parole, rispettando:
     *     - $max come limite “logico”
     *     - HARD_MAX_CHARS come limite assoluto
     * - fa una pass veloce per evitare un ultimo chunk ridicolmente corto
     * - aggiunge overlap tra chunk (basato su parole) senza superare HARD_MAX_CHARS
     *
     * @return string[] Elenco di chunk testuali pronti per embedding / RAG
     */
    public function chunkText(
        string $text,
        int $min = 400,
        int $max = 1500,
        int $overlap = 250
    ): array {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // Prova a correggere alcuni difetti tipici del testo estratto da PDF
        $text = $this->fixMissingSpaces($text);

        // 1) Splitta per paragrafi (due o più newline consecutivi)
        $parts = preg_split("/\R{2,}/u", $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $baseChunks = [];
        $buffer     = '';

        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }

            $pLen = mb_strlen($p, 'UTF-8');

            // Se il paragrafo è già troppo lungo, lo spezzettiamo subito
            if ($pLen > $max) {
                // Flush eventuale del buffer corrente
                if ($buffer !== '') {
                    foreach ($this->splitIntoChunks($buffer, $max) as $chunk) {
                        $chunk = trim($chunk);
                        if ($chunk !== '') {
                            $baseChunks[] = $chunk;
                        }
                    }
                    $buffer = '';
                }

                foreach ($this->splitIntoChunks($p, $max) as $chunk) {
                    $chunk = trim($chunk);
                    if ($chunk !== '') {
                        $baseChunks[] = $chunk;
                    }
                }

                continue;
            }

            // Proviamo ad accumulare paragrafi nel buffer finché restiamo <= $max
            if ($buffer === '') {
                $buffer = $p;
                continue;
            }

            $candidate = $buffer . "\n\n" . $p;
            $len       = mb_strlen($candidate, 'UTF-8');

            if ($len <= $max) {
                // Ci sta ancora nel chunk "ideale"
                $buffer = $candidate;
            } else {
                // Il nuovo paragrafo farebbe sforare $max
                // → chiudiamo il buffer attuale come chunk
                foreach ($this->splitIntoChunks($buffer, $max) as $chunk) {
                    $chunk = trim($chunk);
                    if ($chunk !== '') {
                        $baseChunks[] = $chunk;
                    }
                }

                // e mettiamo il paragrafo corrente in un nuovo buffer
                $buffer = $p;
            }
        }

        // Flush finale del buffer, se è rimasto qualcosa
        if ($buffer !== '') {
            foreach ($this->splitIntoChunks($buffer, $max) as $chunk) {
                $chunk = trim($chunk);
                if ($chunk !== '') {
                    $baseChunks[] = $chunk;
                }
            }
        }

        // 2) Se l'ultimo chunk è troppo corto rispetto al minimo, uniscilo al precedente
        $baseChunks = $this->mergeLastIfTooShort($baseChunks, $min);

        // 3) Aggiungi overlap tra chunk basato su parole, ma senza superare HARD_MAX_CHARS
        $finalChunks = $this->applyOverlap($baseChunks, $overlap);

        return $finalChunks;
    }

    /**
     * Spezza una stringa in chunk "ragionevoli" usando frasi e, se necessario, parole.
     * Garantisce che nessun chunk superi HARD_MAX_CHARS.
     *
     * @return string[]
     */
    private function splitIntoChunks(string $text, int $maxLen): array
    {
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        // Non andiamo mai oltre l'hard limit assoluto
        $maxLen = min($maxLen, self::HARD_MAX_CHARS);

        // 1) Prova a splittare per frasi
        $sentences = preg_split(
            '/(?<=[\.!?])\s+/u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [$text];

        $chunks = [];
        $buffer = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $sLen = mb_strlen($sentence, 'UTF-8');

            // Se la singola frase è già più lunga di $maxLen, spezza per parole
            if ($sLen > $maxLen) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer   = '';
                }

                foreach ($this->splitByWords($sentence, $maxLen) as $wChunk) {
                    $wChunk = trim($wChunk);
                    if ($wChunk !== '') {
                        $chunks[] = $wChunk;
                    }
                }

                continue;
            }

            // Prova ad aggiungerla al buffer
            $candidate = $buffer === '' ? $sentence : $buffer . ' ' . $sentence;
            $len       = mb_strlen($candidate, 'UTF-8');

            if ($len <= $maxLen) {
                $buffer = $candidate;
            } else {
                // Il buffer attuale va bene, chiudilo e ricomincia
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                }
                $buffer = $sentence;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        // Safety finale: tutto comunque <= HARD_MAX_CHARS
        $safe = [];
        foreach ($chunks as $c) {
            $cLen = mb_strlen($c, 'UTF-8');
            if ($cLen <= self::HARD_MAX_CHARS) {
                $safe[] = $c;
                continue;
            }

            foreach ($this->splitByWords($c, self::HARD_MAX_CHARS) as $wChunk) {
                $wChunk = trim($wChunk);
                if ($wChunk !== '') {
                    $safe[] = $wChunk;
                }
            }
        }

        return $safe;
    }

    /**
     * Split "brutale" per parole, garantendo chunk <= $maxLen.
     *
     * @return string[]
     */
    private function splitByWords(string $text, int $maxLen): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $chunks = [];
        $buffer = '';

        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') {
                continue;
            }

            $candidate = $buffer === '' ? $word : $buffer . ' ' . $word;
            $len       = mb_strlen($candidate, 'UTF-8');

            if ($len <= $maxLen) {
                $buffer = $candidate;
                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
            }

            // Se la singola parola supera il limite, taglio brutale
            if (mb_strlen($word, 'UTF-8') > $maxLen) {
                $chunks[] = mb_substr($word, 0, $maxLen, 'UTF-8');
                $buffer   = '';
            } else {
                $buffer = $word;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    /**
     * Unisce l’ultimo chunk al precedente se è molto più corto del minimo desiderato.
     */
    private function mergeLastIfTooShort(array $chunks, int $min): array
    {
        $count = count($chunks);
        if ($count < 2) {
            return $chunks;
        }

        $last     = $chunks[$count - 1];
        $lastLen  = mb_strlen($last, 'UTF-8');

        if ($lastLen >= $min) {
            return $chunks;
        }

        $prev     = $chunks[$count - 2];
        $merged   = $prev . "\n\n" . $last;
        $mergedLen = mb_strlen($merged, 'UTF-8');

        if ($mergedLen <= self::HARD_MAX_CHARS) {
            $chunks[$count - 2] = $merged;
            array_pop($chunks);
        }

        return $chunks;
    }

    /**
     * Applica overlap tra chunk, usando le *ultime parole* del chunk precedente.
     * L'overlap è espresso in "caratteri obiettivo", non in numero di parole.
     * Si assicura di non superare HARD_MAX_CHARS.
     *
     * @param string[] $chunks
     * @return string[]
     */
    private function applyOverlap(array $chunks, int $overlapChars): array
    {
        if ($overlapChars <= 0 || count($chunks) === 0) {
            return $chunks;
        }

        $final = [];
        $count = count($chunks);

        for ($i = 0; $i < $count; $i++) {
            $chunk = trim($chunks[$i]);
            if ($chunk === '') {
                continue;
            }

            // Nessun overlap per il primo chunk
            if ($i === 0) {
                $final[] = $chunk;
                continue;
            }

            $prev = $chunks[$i - 1];

            // Quanto spazio abbiamo per il prefisso, restando entro HARD_MAX_CHARS?
            $chunkLen  = mb_strlen($chunk, 'UTF-8');
            $available = self::HARD_MAX_CHARS - $chunkLen - 2; // -2 per "\n\n"

            if ($available <= 0) {
                // Non c'è spazio per overlap, teniamo solo il chunk
                $final[] = $chunk;
                continue;
            }

            // L'overlap reale è il min tra richiesto e disponibile
            $effectiveOverlap = min($overlapChars, $available);

            $prefix = $this->buildWordOverlap($prev, $effectiveOverlap);
            if ($prefix === '') {
                $final[] = $chunk;
                continue;
            }

            $candidate = $prefix . "\n\n" . $chunk;

            // Safety extra, nel caso l'overlap sia ancora troppo grande
            if (mb_strlen($candidate, 'UTF-8') > self::HARD_MAX_CHARS) {
                $final[] = $chunk;
                continue;
            }

            $final[] = $candidate;
        }

        return $final;
    }

    /**
     * Costruisce un overlap basato su parole (non su caratteri).
     * Prende le ultime parole del chunk precedente finché non
     * supera approssimativamente overlapChars caratteri.
     */
    private function buildWordOverlap(string $prev, int $overlapChars): string
    {
        $prev = trim($prev);
        if ($overlapChars <= 0 || $prev === '') {
            return '';
        }

        $words = preg_split('/\s+/u', $prev, -1, PREG_SPLIT_NO_EMPTY);
        if (!$words || count($words) === 0) {
            return '';
        }

        $selected = [];
        $totalLen = 0;

        // Parti dalla fine e risali
        for ($i = count($words) - 1; $i >= 0; $i--) {
            $w = $words[$i];

            $wLen = mb_strlen($w, 'UTF-8');

            // +1 per lo spazio che si aggiunge tra le parole
            if ($totalLen > 0) {
                $wLen += 1;
            }

            if ($totalLen + $wLen > $overlapChars && !empty($selected)) {
                break;
            }

            array_unshift($selected, $w);
            $totalLen += $wLen;

            if ($totalLen >= $overlapChars) {
                break;
            }
        }

        return implode(' ', $selected);
    }

    /**
     * Corregge alcuni casi tipici di "spazi mancanti" dovuti all'estrazione da PDF:
     *  1) Nessuno spazio dopo . ! ? ; :
     *  2) ALL-CAPS subito seguite da parola capitalizzata (MOTIVAZIONIRuolo)
     *  3) minuscola seguita da maiuscola senza spazio (standard.Origini)
     */
    public function fixMissingSpaces(string $text): string
    {
        // 1) Spazio dopo ., !, ?, ;, : se NON c'è già uno spazio
        // es: "dominanti:Carisma" -> "dominanti: Carisma"
        $text = preg_replace(
            '/([\.!?;:])([^\s])/u',
            '$1 $2',
            $text
        );

        // 2) Spazio tra parola ALL-CAPS e parola Capitalized attaccate
        // es: "MOTIVAZIONIRuolo" -> "MOTIVAZIONI Ruolo"
        //     "PSICOLOGICOEtà"   -> "PSICOLOGICO Età"
        $text = preg_replace(
            '/\b([A-ZÀ-ÖØ-Ý]{2,})([A-ZÀ-ÖØ-Ý][a-zà-öø-ÿ]+)/u',
            '$1 $2',
            $text
        );

        // 3) Spazio tra minuscola e maiuscola attaccate (caso generico)
        // es: "standard.Origini" -> "standard. Origini"
        $text = preg_replace(
            '/([\p{Ll}])([\p{Lu}])/u',
            '$1 $2',
            $text
        );

        return $text;
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
- Nessuna necessità di clusterizzazione (IV-FFLAT)

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
