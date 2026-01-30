# ELARA
## 1. Definizione
ELARA (Embedding Linking & Retrieval Answering) è un motore RAG progettato per collegare embedding semantici, recuperare i contenuti più rilevanti e generare risposte precise basate sulle fonti disponibili. La pipeline integra:
* vettorizzazione dei contenuti (Embedding)
* correlazione tra query e documenti (Linking)
* ricerca e ordinamento dei frammenti pertinenti (Retrieval)
* generazione e formulazione delle risposte (Answering)

Il tutto con un flusso semplice, trasparente e controllabile.

## 1.1 Architettura del Progetto
```
src/
  AI/                 # Client per Ollama, OpenAI, Gemini (AiClientInterface)
  Command/            # CLI: index-docs, list-docs, reset-rag-schema, user-*, api-token
  Controller/         # Dashboard, API REST /api/chat, profili RAG
  Entity/             # DocumentFile, DocumentChunk, ApiToken, User
  Middleware/         # Supporto pgvector (IVF-FLAT probes)
  Model/              # DTO: IndexSummary, FileIndexStatus, IndexedFileResult
  Rag/                # RagProfileManager, ActiveProfileStorage, EmbeddingSchemaInspector
  Repository/         # Query vettoriali ottimizzate (cosine similarity)
  Security/           # Autenticazione via API token
  Service/            # DocsIndexer, ChatbotService, ChunkingService, DocumentTextExtractor
  Twig/               # Componenti UX (modali, badge, Stimulus controllers)

config/packages/
  rag_profiles.yaml   # Definizione profili RAG

templates/            # UI Tailwind/DaisyUI
var/knowledge/        # Knowledge base indicizzabile
docs/                 # Documentazione tecnica (mirror in var/knowledge/)
```

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
### Eseguire i comandi:
```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```
> **Nota importante sulle migration Doctrine**  
> Ad ogni `doctrine:migrations:diff`(make:migration), Doctrine prova a rimuovere l'indice vettoriale HNSW perché non è modellabile nei metadata. Prima di eseguire una nuova migration aperta sotto `migrations/`, eliminare manualmente la riga  
> `$this->addSql('DROP INDEX document_chunk_embedding_hnsw');`  
> altrimenti l'indice verrebbe cancellato dalla tabella `document_chunk`.

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
***Mantenere due indici vettoriali sullo stesso campo aumenta spazio occupato e tempi di scrittura senza benefici.***

> ***Gli indici vettoriali IVF-FLAT e HNSW, sono da considerare mutualmente esclusivi***

> **Quale indice usare?**  
> - **HNSW** è il default per dataset piccoli/medi (documentazione interna, manuali, knowledge base self-hosted) e funziona senza tuning aggiuntivo.  
> - **IVF-FLAT** è pensato per dataset molto estesi (milioni di chunk) e richiede la calibrazione di `lists`/`probes` e REINDEX periodici.  
> - Pgvector utilizza un indice alla volta: mantenere due indici paralleli comporta solo costi addizionali.

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
      gemini-flash:
        label: 'Gemini · 2.5 Flash'
        backend: 'gemini'
        chunking: { min: 380, max: 1200, overlap: 220 }
        retrieval: { top_k: 5, min_score: 0.60 }
        ai:
          chat_model: 'gemini-2.5-flash'
          embed_model: 'gemini-embedding-001'
          embed_dimension: 1536
          test_mode: false
          offline_fallback: true
```

#### Profili disponibili
| Profilo | Backend | Modello Chat | Modello Embedding | Dimensione |
|---------|---------|--------------|-------------------|------------|
| `ollama-bgem3` | Ollama | llama3.2 | bge-m3 | 1024 |
| `openai-mini` | OpenAI | gpt-4.1-mini | text-embedding-3-small | 1536 |
| `gemini-flash` | Gemini | gemini-2.5-flash | gemini-embedding-001 | 1536 |
| `offline-test` | Ollama | llama3.2 | bge-m3 (fake) | 1024 |

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
# ora arriva tutto dal profilo in config/packages/rag_profiles.yaml
# lascio come referenzce
RAG_PROFILE=ollama-bgem3
AI_BACKEND=ollama          # fallback legacy per servizi non profilati
SHOW_SOURCES=false
TOP_K=5

# Ollama
# ora arriva tutto dal profilo in config/packages/rag_profiles.yaml
OLLAMA_HOST=http://localhost:11434
OLLAMA_CHAT_MODEL=llama3.2
OLLAMA_EMBED_MODEL=bge-m3
OLLAMA_EMBED_DIMENSION=1024

# OpenAI
# ora arriva tutto dal profilo in config/packages/rag_profiles.yaml
#AI_BACKEND=openai
#OPENAI_API_KEY=sk-...
#OPENAI_CHAT_MODEL=gpt-5.1-mini
#OPENAI_EMBED_MODEL=text-embedding-3-small
#OPENAI_EMBED_DIMENSION=768

# Gemini
# ora arriva tutto dal profilo in config/packages/rag_profiles.yaml
#AI_BACKEND=gemini
#GEMINI_API_KEY=...
#GEMINI_CHAT_MODEL=gemini-2.5-flash
#GEMINI_EMBED_MODEL=gemini-embedding-001
#GEMINI_EMBED_DIMENSION=768

## Cache chatbot
# TTL in secondi per cache risposta/fonti; se 0 la cache è disabilitata
APP_CHAT_CACHE_TTL=0

## RAG Test Mode e Fallback
## configurati nel profilo
# APP_AI_TEST_MODE=false        
# APP_AI_OFFLINE_FALLBACK=true

## Uso solo indici hwsn
# Postgres pgvector - sonde per indice ivfflat
# APP_IVFFLAT_PROBES=10

# Xdebug vscode
XDEBUG_MODE=debug
XDEBUG_CONFIG="client_host=127.0.0.1 client_port=9003"
```
### Cache APCu(opzionale)
Se si decide si usare la cache, installare l'estensione per php:
```bash
sudo apt install php-apcu
```
abilitare il CLI in /etc/php/8.{v}/mods-available/apcu.ini:
```ini
apc.enable_cli=1 
```
per verificare che il supporto per APCu è abilitato:
```bash
php -i | grep -i apcu
```
si vedrà una cosa simile a questa:
```bash
apcu
APCu Support => Enabled
```
nel file config/packages/cache.yaml
```yaml
# config/packages/cache.yaml
framework:
    cache:
        app: cache.adapter.apcu
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

## 5. Command Symfony per indicizzare i file
### Esempi di utilizzo
Tutti i comandi CLI elencati sono Symfony Command eseguiti via `php bin/console`. I file da indicizzare devono essere caricati nella cartella var/knowledge
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
## 6. Command Symfony per vedere l'elenco dei file indicizzati
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
# 7. Command Symfony per rimuovere file dall'indice
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
# 8. Gestione utenti (Symfony Command)
## 1. Creare un utente con ruoli
- Ruoli ripetibili:  
  ```bash
  php bin/console app:user-create email@example.com --role=ROLE_ADMIN --role=ROLE_USER
  ```
- Se ometti `--role`, viene assegnato comunque `ROLE_USER` di default.

## 2. Aggiungere o rimuovere ruoli a un utente esistente
- Aggiungere ruoli:  
  ```bash
  php bin/console app:user-role email@example.com --add=ROLE_ADMIN --add=ROLE_EDITOR
  ```
- Rimuovere ruoli (ROLE_USER non viene mai eliminato):  
  ```bash
  php bin/console app:user-role email@example.com --remove=ROLE_EDITOR
  ```

# 9. Symfony Command per generare API token
## 1. ttl scadenza di default 1 anno
```bash
php bin/console app:api-token:create user@email.dev
```
## 2. Con scadenza impostata tramite il parametro ttl, indicata in ore
```bash
php bin/console app:api-token:create user@email.dev --ttl=48
```

# 10. API come utilizzarla(ApiTokenAuthenticator)
Dashboard dedicata: da **Status → API Token** puoi vedere elenco, utilizzi e revocare rapidamente i token in uso.

## 1. Normale
```bash
curl -X POST https://localhost/api/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <token_generato>" \
  -d '{"question":"Riassumi la pipeline"}'
```
## 2. Streaming
```bash
curl -X POST https://localhost/api/chat/stream \
  -H "Content-Type: application/json" \
  -H "Accept: text/event-stream" \
  -H "Authorization: Bearer <token_generato>" \
  -d '{"question":"Riassumi la pipeline"}'
```

# 11. Xdebug e vscode debugger configuration
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

# 12. Riepilogo Comandi CLI
Tutti i comandi sono eseguiti via `php bin/console`:

### Indicizzazione
| Comando | Descrizione |
|---------|-------------|
| `app:index-docs` | Indicizza file nuovi/modificati |
| `app:index-docs --force-reindex` | Reindicizza tutto ignorando hash |
| `app:index-docs --rag-profile=<nome>` | Indicizza con profilo specifico |
| `app:index-docs --dry-run` | Simulazione (nessuna scrittura) |
| `app:index-docs --test-mode` | Embedding finti per test locale |
| `app:list-docs` | Elenca documenti indicizzati |
| `app:list-docs --path=<pattern>` | Filtra per path |
| `app:unindex-file <pattern>` | Rimuovi file dall'indice |

### Gestione Schema RAG
| Comando | Descrizione |
|---------|-------------|
| `app:reset-rag-schema --force` | Reset schema vettoriale (⚠️ cancella tutti i chunk) |
| `app:knowledge-sync` | Sincronizza `docs/` → `var/knowledge/` |

### Utenti e Autenticazione
| Comando | Descrizione |
|---------|-------------|
| `app:user-create <email> --role=ROLE_ADMIN` | Crea utente con ruoli |
| `app:user-role <email> --add=ROLE_ADMIN` | Aggiungi ruolo |
| `app:user-role <email> --remove=ROLE_EDITOR` | Rimuovi ruolo |
| `app:api-token:create <email> --ttl=720` | Genera token API (TTL in ore) |

### Utility
| Comando | Descrizione |
|---------|-------------|
| `app:generate-favicon` | Genera favicon da template |

# 13. Da implementare
- **Scheduler re-index** — Pianificazione via cron/Messenger per scansioni incrementali automatiche
- **Audit chat** — Log minimale (utente/timestamp/latenza/modello) per monitoraggio
- **Test regressione RAG** — Comando per confrontare risposte con baseline
- **Alerting stato indice** — Webhooks/email quando chunk non cercabili superano soglia

# 14. Licenza
Il progetto è distribuito con licenza MIT. I dettagli completi sono disponibili nel file `LICENSE`.
