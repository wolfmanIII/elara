# ELARA
## 1. Definizione
ELARA (Embedding Linking & Retrieval Answering) è un motore RAG progettato per collegare embedding semantici, recuperare i contenuti più rilevanti e generare risposte precise basate sulle fonti disponibili. La pipeline integra:
* vettorizzazione dei contenuti (Embedding)
* correlazione tra query e documenti (Linking)
* ricerca e ordinamento dei frammenti pertinenti (Retrieval)
* generazione e formulazione delle risposte (Answering)

Il tutto con un flusso semplice, trasparente e controllabile.

## 2. Dipendenze aggiuntive che verranno installate con il comando:
```bash
composer install
```
* smalot/pdfparser
* phpoffice/phpword
* openai-php/client
* partitech/doctrine-pgvector
* symfony/uid
* symfonycasts/tailwind-bundle
* league/commonmark
* symfony/ux-twig-component
### Interfaccia grafica, Tailwind, Tipography e DaisyUI
#### Installare nvm (nodejs version manager)
```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.1/install.sh | bash
```
#### Aggiornare il proprio profilo utente, file .bash_profile o .bashrc nella propria home directory
```bash
export NVM_DIR="$([ -z "${XDG_CONFIG_HOME-}" ] && printf %s "${HOME}/.nvm" || printf %s "${XDG_CONFIG_HOME}/nvm")"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
```
#### Ricaricare la configurazione della shell
```bash
source ~/.bashrc
```
### Installare nodejs e i plugin aggiuntivi per Tailwind
```bash
nvm install --lts
npm init
npm install -D @tailwindcss/typography
npm i -D daisyui@latest
```
## 3. PostgreSQL + pgvector + Doctrine
### Installare postgres + pgvector
```bash
sudo apt install postgresql-18 postgresql-18-pgvector
```
### Nel database PostgreSQL, eseguire:
```sql
CREATE EXTENSION IF NOT EXISTS vector;
```
Sono necessari i permessi relativi
### Eseguire il comando:
```bash
php bin/console doctrine:migrations:migrate
```
### Creare indice HNSW per velocizzare le ricerche(tabella document_chunk)
```sql
CREATE INDEX document_chunk_embedding_hnsw
ON document_chunk
USING hnsw (embedding vector_cosine_ops);
```
### Creare indice IVF-FLAT per velocizzare le ricerche(tabella ducument_chunk)
```sql
CREATE INDEX document_chunk_embedding_ivfflat
ON document_chunk
USING ivfflat (embedding vector_cosine_ops)
WITH (lists = 100);
```
***Attenzione, non è utile avere due indici vettoriali diversi sullo stesso campo, è solo uno spreco di spazio e di tempo in scrittura.***

> ***Gli indici vettoriali IVF-FLAT e HNSW, sono da considerare mutualmente esclusivi***

### Cos'è pgvector
__pgvector__ è un’estensione per PostgreSQL che aggiunge:
* un tipo di colonna: vector(N) → un array di N numeri (float)
* operatori e funzioni per confrontare questi vettori (distanze, similarità)
* indici speciali (ivfflat / hnsw) per rendere le ricerche veloci

Nel nostro schema abbiamo:
```php
#[ORM\Column(type: 'vector', length: 1024)]
private array $embedding;
```
questo campo su `DocumentChunk` è letteralmente:  
***il posto dove salviamo il vettore di embedding del chunk di testo***
### Definizione di embedding

Quando viene indicizzato un chunk:
* viene preso il testo (__$chunkText__)
* viene passato al modello `bge-m3` di Ollama
* il modello restituisce un array di 1024 numeri tipo:
```json
[-0.023, 0.114, ..., 0.002]
```
Questo vettore rappresenta il significato del testo in uno spazio numerico.  
In questo spazio:  
Testi “simili” sono “vicini”; testi diversi sono “lontani”.

`pgvector` serve esattamente a questo:  
Postgres lo usa per memorizzare questi vettori e confrontarli.

All'interno dell'applicativo:
* quando indicizzi → salvi per ogni `DocumentChunk` il suo `embedding` (vector(1024))
* quando interroghi il chatbot → calcoli l’`embedding` della domanda e lo confronti con quelli salvati.
### Cos’è cosine_similarity e cosa fa nella query
Ecco un esempio:
```php
$qb = $this->em->createQueryBuilder()
    ->select('c', 'f')
    ->from(DocumentChunk::class, 'c')
    ->join('c.file', 'f')
    ->where('c.embedding IS NOT NULL')
    ->orderBy('cosine_similarity(c.embedding, :vec)', 'DESC')
    ->setMaxResults(5)
    ->setParameter('vec', $queryVec);
```
Qui accadono 2 cose molto importanti:
1. `:vec` è l’embedding della domanda (array di 1024 float).
2. `cosine_similarity(c.embedding, :vec)` è una funzione di pgvector che calcola quanto sono simili i due vettori.
### Cos’è la cosine similarity in parole povere
Immagina ogni embedding come una freccia in uno spazio a 1024 dimensioni 😅

La `cosine similarity` misura l’angolo tra le due frecce(domanda, chunk):
* angolo piccolo → frecce “puntano” nella stessa direzione → `contenuti simili`
* angolo grande → frecce “puntano” in direzioni diverse → `contenuti diversi`

Il valore è tra -1 e 1:
* 1 → identici
* 0 → non correlati
* -1 → opposti

Quando si esegue:
```sql
ORDER BY cosine_similarity(c.embedding, :vec) DESC
```
si sta chiedendo:  
***“Recupera per primi i chunk il cui significato è più vicino al significato della domanda”.***
### In config/packages/doctrine.yaml aggiungi il tipo e le funzioni DQL:
```yaml
doctrine:
  dbal:
    # ... il config solito (url, ecc.)
    types:
      # Uuid Postgres
      uuid: Symfony\Bridge\Doctrine\Types\UuidType

      # Postgres pg-vector
      vector: Partitech\DoctrinePgVector\Type\VectorType

  orm:
    # ...

    dql:
      string_functions:
        # cosine similarity Postgres
        cosine_similarity: Partitech\DoctrinePgVector\Query\CosineSimilarity
        distance: Partitech\DoctrinePgVector\Query\Distance
```
## 4. Backend AI Ollama | OpenAI | Gemini
### Variabili d'ambiente, nel file .env.local
```env
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

# Ollama
AI_BACKEND=ollama
OLLAMA_HOST=http://localhost:11434
OLLAMA_CHAT_MODEL=llama3.2
OLLAMA_EMBED_MODEL=bge-m3
OLLAMA_EMBED_DIMENSION=1024

# OpenAI
#AI_BACKEND=openai
#OPENAI_API_KEY=sk-...
#OPENAI_CHAT_MODEL=gpt-4.1-mini
#OPENAI_EMBED_MODEL=text-embedding-3-small
#OPENAI_EMBED_DIMENSION=768

# Gemini
#AI_BACKEND=gemini
#GEMINI_API_KEY=...
#GEMINI_CHAT_MODEL=gemini-1.5-flash
#GEMINI_EMBED_MODEL=text-embedding-004
#GEMINI_EMBED_DIMENSION=768

## RAG Test Mode e Fallback
APP_AI_TEST_MODE=true
APP_AI_OFFLINE_FALLBACK=false

## Uso solo indici hwsn
# Postgres pgvector - sonde per indice ivfflat
# APP_IVFFLAT_PROBES=10
```
### Configurazione AI_BACKEND, PDF parser, IVF-FLAT Probes, nel file services.yaml
```yaml
parameters:
  # ...

  ai.backend: '%env(AI_BACKEND)%' # ollama | openai | gemini

services:
  # ...

  # abilta il servizio per il parsing dei file PDF
  Smalot\PdfParser\Parser: ~

  ## Alla fine ho deciso di utilizzare solo indici hnsw
  # Middleware per abilitare l'uso delle sonde sugli indici ivfflat(pgvector)
  # App\Middleware\PgvectorIvfflatMiddleware:
  #    arguments:
  #        $probes: '%env(int:APP_IVFFLAT_PROBES)%'

  # AiClientInterface per gestire il backend Ollama | OpenAi | Gemini
  App\AI\AiClientInterface:
      factory: [ '@App\AI\AiClientFactory', 'create' ]
      arguments: [ '%ai.backend%' ]
```
Tramite la variabile di ambiente `APP_IVFFLAT_PROBES`, impostiamo il rapporto qualità velocità del nostro sistema RAG:
* 5–10 = super veloce(si ok, ma dipende dal hardware)
* 20–30 = molto preciso
* 50–100 = qualità altissima (RAG più consistente, più lento)

***Alla fine ho deciso di utilizzare solo indici HNSW***

## 5. Command per indicizzare i file
### Esempi di utilizzo
I file da indicizzare devono essere caricati nella cartella var/knowledge
### 1. Full index, sfruttando hash (solo file nuovi/modificati)
```bash
php bin/console app:index-docs
```
### 2. Reindicizza TUTTO ignorando hash
```bash
php bin/console app:index-docs --force-reindex
```
### 3. Solo la sotto-cartella manuali/
```bash
php bin/console app:index-docs --path=manuali --path=log/2025
```
### 4. Simulazione pura (solo vedere cosa succederebbe)
```bash
php bin/console app:index-docs --dry-run
```
### 5. Indicizzare davvero, ma con embeddings finti (test locale)
```bash
php bin/console app:index-docs --test-mode
# oppure: APP_AI_TEST_MODE=true php bin/console app:index-docs
```
## 6. Command per vedere l'elenco dei file indicizzati
### Esempi di utilizzo
### 1. Elenco base (max 50):
```bash
php bin/console app:list-docs
```
### 2. Filtra per path (es. solo roba con “trast” nel nome):
```bash
php bin/console app:list-docs --path=trast
```
### 3. Mostra fino a 200 file:
```bash
php bin/console app:list-docs --limit=200
```
### 4. Path + limit insieme:
```bash
php bin/console app:list-docs --path=manuali --limit=20
```
# 7. Command per rimuovere file dell'indice
### Esempi di utilizzo
### 1. Eliminare un singolo file indicizzato
```bash
php bin/console app:unindex-file "manuali/helix.md"
```
### 2. Eliminare tutti i file sotto una cartella
```bash
php bin/console app:unindex-file "^manuali/"
```
### 3. Eliminare tutti i PDF
```bash
php bin/console app:unindex-file "\\.pdf$"
```
### 4. Eliminare TUTTO l’indice (equivalente a reset totale)
```bash
php bin/console app:unindex-file ".*"
```
