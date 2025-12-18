# ELARA
## 1. Definizione
ELARA (Embedding Linking & Retrieval Answering) è un motore RAG progettato per collegare embedding semantici, recuperare i contenuti più rilevanti e generare risposte precise basate sulle fonti disponibili. La pipeline integra:
* vettorizzazione dei contenuti (Embedding)
* correlazione tra query e documenti (Linking)
* ricerca e ordinamento dei frammenti pertinenti (Retrieval)
* generazione e formulazione delle risposte (Answering)

Il tutto con un flusso semplice, trasparente e controllabile.

## 2. Installare le dipendenze e abilitare Symfony UX Live Components:
```bash
composer install
php bin/console importmap:require @symfony/ux-live-component
```
### Elenco delle dipendenze che verrannno installate:
* smalot/pdfparser
* phpoffice/phpword
* openai-php/client
* partitech/doctrine-pgvector
* symfony/uid
* symfony/ux-twig-component
* symfony/ux-live-component
* symfonycasts/tailwind-bundle
* league/commonmark
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
### Impostare workers e agganciarli al symfony CLI
nel file .symfony.local.yaml - se non c'è, crearlo nella directory del progetto
```yaml
workers:
    # ...

    tailwind:
        cmd: ['symfony', 'console', 'tailwind:build', '--watch']
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
> **Nota importante sulle migration Doctrine**  
> Ad ogni `doctrine:migrations:diff`(make:migration), Doctrine prova a rimuovere l'indice vettoriale HNSW perché non è modellabile nei metadata. Prima di eseguire una nuova migration aperta sotto `migrations/`, eliminare manualmente la riga  
> `\$this->addSql('DROP INDEX document_chunk_embedding_hnsw');`  
> così si evita che l'indice venga cancellato dalla tabella `document_chunk`.

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

> **Quale indice usare?**  
> - **HNSW** è il default consigliato per dataset piccoli/medi (documentazione interna, manuali, knowledge base self-hosted): offre ottima precisione senza tuning.  
> - **IVF-FLAT** va considerato solo con dataset enormi (milioni di chunk) e con tempo per calibrare `lists`/`probes` e pianificare REINDEX periodici.  
> - Evita di abilitarli entrambi: pgvector sceglie un indice alla volta e mantenere il secondo porta solo costi addizionali.

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
public function findTopKCosineSimilarity(array $embedding): array
{
    return $this->createQueryBuilder('c')
        ->select('c.id')
        ->addSelect('c.content AS chunk_content')
        ->addSelect('c.chunkIndex AS chunk_index')
        ->addSelect('f.path AS file_path')
        ->addSelect('cosine_similarity(c.embedding, :vec) AS similarity')
        ->join('c.file', 'f')
        ->where('c.embedding IS NOT NULL')
        ->andWhere('c.searchable = true')
        ->andWhere('cosine_similarity(c.embedding, :vec) > :minScore')
        ->orderBy('similarity', 'DESC')
        ->setMaxResults((int)$_ENV['TOP_K'])
        ->setParameter('vec', $embedding, 'vector')
        ->setParameter('minScore', 0.55)
        ->getQuery()
        ->getResult();
}
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
### Preset RAG (config/packages/rag_profiles.yaml)
Definisco i profili una sola volta, ad esempio:
```yaml
parameters:
  rag_profiles:
    default_profile: 'ollama-bgem3'
    presets:
      ollama-bgem3:
        label: 'Ollama · bge-m3'
        backend: 'ollama'
        chunking: { min: 400, max: 1400, overlap: 250 }
        retrieval: { top_k: 5, min_score: 0.55 }
        ai:
          chat_model: 'llama3.2'
          embed_model: 'bge-m3'
          embed_dimension: 1024
          test_mode: false
          offline_fallback: true
      openai-mini:
        label: 'OpenAI · gpt-4.1-mini'
        backend: 'openai'
        chunking: { min: 380, max: 1200, overlap: 220 }
        retrieval: { top_k: 5, min_score: 0.60 }
        ai:
          chat_model: 'gpt-4.1-mini'
          embed_model: 'text-embedding-3-small'
          embed_dimension: 1536
          test_mode: false
          offline_fallback: true
```
Per commutare basta impostare `RAG_PROFILE=<nome>` (o usare l'opzione CLI `--rag-profile=<nome>` durante l'indicizzazione).

#### Switch tramite UI
- Dashboard `Status → RAG Profiles` mostra la lista dei preset con badge *Attivo* e pulsante “Attiva” direttamente nella riga.
- Al click il pulsante si disabilita (Stimulus controller `rag_profile_switch`) per evitare click multipli finché lo switch non termina.
- Se la dimensione dell’embedding salvata nello schema non coincide con quella richiesta dal profilo selezionato, compare un alert con i due comandi da eseguire nell’ordine corretto:
  1. `php bin/console app:reset-rag-schema --force`
  2. `php bin/console app:index-docs --force-reindex`
- In basso sono sempre disponibili le scorciatoie CLI (`RAG_PROFILE=<nome>`, `app:index-docs --rag-profile=<nome>`) nel caso si preferisca uno switch via terminale.

### Variabili d'ambiente, nel file .env.local
```env
# Database PostgreSQL 18
DATABASE_URL="postgresql://app:!ChangeMe!@127.0.0.1:5432/app?serverVersion=16&charset=utf8"

# Parametri AI Backend
RAG_PROFILE=ollama-bgem3   # preset definito in config/packages/rag_profiles.yaml
AI_BACKEND=ollama          # fallback legacy per servizi non profilati
SHOW_SOURCES=true          # oggi arriva dal profilo, lo lascio come reference
TOP_K=5                   # idem, i servizi core leggono retrieval.top_k

# Ollama
OLLAMA_HOST=http://localhost:11434
OLLAMA_CHAT_MODEL=llama3.2
OLLAMA_EMBED_MODEL=bge-m3
OLLAMA_EMBED_DIMENSION=1024

# OpenAI
#AI_BACKEND=openai
#OPENAI_API_KEY=sk-...
#OPENAI_CHAT_MODEL=gpt-5.1-mini
#OPENAI_EMBED_MODEL=text-embedding-3-small
#OPENAI_EMBED_DIMENSION=768

# Gemini
#AI_BACKEND=gemini
#GEMINI_API_KEY=...
#GEMINI_CHAT_MODEL=gemini-2.5-flash
#GEMINI_EMBED_MODEL=gemini-embedding-001
#GEMINI_EMBED_DIMENSION=1536

## RAG Test Mode e Fallback
APP_AI_TEST_MODE=true        # ora configurato nel profilo, tengo questi flag per override veloci
APP_AI_OFFLINE_FALLBACK=false

## Uso solo indici hwsn
# Postgres pgvector - sonde per indice ivfflat
# APP_IVFFLAT_PROBES=10

# Xdebug vscode
XDEBUG_MODE=debug
XDEBUG_CONFIG="client_host=127.0.0.1 client_port=9003"
```
### Configurazione servizi (RagProfileManager, AiClient, PDF parser)
```yaml
services:
  # ...

  # abilta il servizio per il parsing dei file PDF
  Smalot\PdfParser\Parser: ~

  ## Alla fine ho deciso di utilizzare solo indici hnsw
  # Middleware per abilitare l'uso delle sonde sugli indici ivfflat(pgvector)
  # App\Middleware\PgvectorIvfflatMiddleware:
  #    arguments:
  #        $probes: '%env(int:APP_IVFFLAT_PROBES)%'

  # Manager dei profili RAG (seleziona preset via env RAG_PROFILE)
  App\Rag\RagProfileManager:
      arguments:
          $config: '%rag_profiles%'
          $envProfile: '%env(default::RAG_PROFILE)%'

  # AiClientInterface per gestire il backend Ollama | OpenAi | Gemini
  App\AI\AiClientInterface:
      factory: [ '@App\AI\AiClientFactory', 'create' ]
      arguments: [ '@App\Rag\RagProfileManager' ]
```
Tramite la variabile di ambiente `APP_IVFFLAT_PROBES`(solo se usato), impostiamo il rapporto qualità velocità del nostro sistema RAG:
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
### 1bis. Full index usando un profilo specifico (es. OpenAI)
```bash
php bin/console app:index-docs --rag-profile=openai-mini
```
### 2. Reindicizza TUTTO ignorando hash
```bash
php bin/console app:index-docs --force-reindex
```
### 3. Solo la sotto-cartella manuali/
```bash
php bin/console app:index-docs --path=manuali --path=log/2025
```
### 4. Simulazione pura (solo per vedere cosa succederebbe )
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
# 8. API come utilizzarla(ApiTokenAuthenticator)
Dashboard dedicata: da **Status → API Token** puoi vedere elenco, utilizzi e revocare rapidamente i token in uso.
## 1. Command per generare token
### ttl scadenza di default 1 anno
```bash
php bin/console app:api-token:create user@email.dev
```
### Con scadenza impostatata tramite il parametro ttl, indicata in ore
```bash
php bin/console app:api-token:create user@email.dev --ttl=48
```
## 2. Normale
```bash
curl -X POST https://localhost/api/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token_generato>" \
  -d '{"question":"Riassumi la pipeline"}'
```
## 3. Streaming
```bash
curl -X POST https://localhost/api/chat/stream \
  -H "Content-Type: application/json" \
  -H "Accept: text/event-stream" \
  -H "Authorization: Bearer <token_generato>" \
  -d '{"question":"Riassumi la pipeline"}'
```

# 9. Xdebug e vscode debugger configuration
## VsCode -> Run & Debug -> Add Configuration
```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Listen for Xdebug",
      "type": "php",
      "request": "launch",
      "port": 9003
    },
    {
      "name": "Symfony CLI (console)",
      "type": "php",
      "request": "launch",
      "program": "${workspaceFolder}/bin/console",
      "cwd": "${workspaceFolder}",
      "args": [
        "app:index-docs",
        "--dry-run"
      ],
      "port": 9003,
      "env": {
        "APP_ENV": "dev",
        "XDEBUG_MODE": "debug"
      }
    }
  ]
}
```
## .env.local
```env
# Xdebug
XDEBUG_MODE=debug
XDEBUG_CONFIG="client_host=127.0.0.1 client_port=9003"
```
## xdebug.ini
```ini

zend_extension=xdebug
xdebug.mode=debug
xdebug.start_with_request=yes
xdebug.client_host=127.0.0.1
xdebug.client_port=9003
xdebug.log_level=0

# docker/WSL
#xdebug.client_host=host.docker.internal  # Oppure l’IP della tua macchina
#xdebug.client_port=9003
#xdebug.mode=debug
```

# 10. Da implementare
## 1. Scheduler di re-index  
comando che pianifica via cron (o Symfony Messenger) scansioni incrementali, con notifica se trova documenti non indicizzati o fallimenti.  
## 2. Audit delle chat
log minimale (utente/timestamp/latency/modello) per capire carico e qualità, magari con filtri su top-k e soglia similitudine usata.
## 3. Test di regressione RAG
un comando che esegue richieste “di riferimento” e confronta score/risposte con baseline, utile prima di cambiare modello embedding o parametri.
## 4. Alerting per stato indice
Live component per aggiungere webhooks/email quando chunk non cercabili superano una soglia o l’indexer fallisce.
