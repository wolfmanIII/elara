# ELARA — API Guide per `/api/chat` con esempi curl (Versione Completa)

Questo documento descrive in modo **completo e aggiornato** come utilizzare l’endpoint REST di ELARA per interrogare il motore RAG tramite HTTP, con particolare attenzione agli esempi **curl**.

L’obiettivo è permettere a sviluppatori e strumenti esterni (script, Postman, integrazioni backend) di:
- inviare domande a ELARA,
- ricevere risposte in formato JSON,
- sfruttare le modalità **normale**, **TEST** e **offline fallback**,
- comprendere i parametri di configurazione (`ENV`) che influenzano il comportamento dell’API.

---

# 📌 1. Endpoint principale

L’endpoint pubblico esposto dall’applicazione è:

```http
POST /api/chat
Content-Type: application/json
```

Protocollo previsto:
- ambiente locale: `https://127.0.0.1:8000/api/chat`
- in produzione: tipicamente dietro reverse proxy (es. Nginx, Caddy, ecc.)

## 1.2 Endpoint streaming

Per risposte in tempo reale (Server-Sent Events):

```http
POST /api/chat/stream
Content-Type: application/json
Accept: text/event-stream
```

L'endpoint streaming restituisce la risposta token per token in formato SSE:

```
data: {"chunk": "La risposta"}
data: {"chunk": " inizia qui..."}
...
data: {"done": true, "sources": ["file1.md", "file2.pdf"]}
```

### Esempio curl streaming
```bash
curl -N -X POST "https://127.0.0.1:8000/api/chat/stream" \
  -H "Content-Type: application/json" \
  -H "Accept: text/event-stream" \
  -H "Authorization: Bearer <token>" \
  -d '{"question": "Cosa fa ELARA?"}'
```

> **Nota**: L'opzione `-N` disabilita il buffering per vedere i chunk in tempo reale.

---

# 📨 2. Struttura della richiesta

## 2.1 JSON minimo richiesto

La richiesta minima accettata dall’API è un JSON con il campo `question`:

```json
{
  "question": "testo della domanda"
}
```

Requisiti:
- `question` **obbligatorio**,
- `question` dev’essere una **stringa non vuota**.

Se il campo è mancante o vuoto, ELARA risponde con un errore strutturato (vedi sezione [6.2](#62-risposte-di-errore)).

---

# 🧪 3. Modalità operative dell’API

Il comportamento dell’endpoint `/api/chat` dipende da alcune variabili di ambiente.

## 3.1 Modalità normale

È la modalità di default.

Flusso:
1. L’API riceve `question`.
2. ChatbotService crea l’**embedding** della domanda.
3. Viene eseguita la **ricerca vettoriale** sui chunk indicizzati.
4. Viene costruito un **contesto RAG**.
5. Viene chiamato il backend AI (Ollama, OpenAI, Gemini) per generare la risposta.
6. Viene restituita una risposta JSON con il testo generato.

## 3.2 Modalità TEST

Abilitata da:

```env
APP_AI_TEST_MODE=true
```

In questa modalità:
- l’API **non chiama** il modello AI,
- ChatbotService esegue una ricerca **testuale** sui chunk (ad es. `LIKE`),
- la risposta contiene estratti/anteprime dei pezzi di documento trovati.

È utile per:
- debug del **retrieval**,
- verificare se la domanda è formulata bene,
- controllare quali chunk vengono selezionati.

## 3.3 Modalità Offline Fallback

Configurata da:

```env
APP_AI_OFFLINE_FALLBACK=true
```

Se attiva, e se il backend AI (Ollama, OpenAI, Gemini) non risponde o genera errore:
- ChatbotService **non fallisce brutalmente**,
- viene eseguita una ricerca vettoriale,
- vengono restituiti estratti testuali rilevanti dai documenti,
- la risposta spiega che la risposta è in modalità fallback.

Questa modalità consente di mantenere un livello minimo di usabilità anche in assenza del modello AI.

---

# ⚙️ 4. Variabili di ambiente rilevanti

Le principali variabili che influenzano il comportamento dell’API sono:

```env
# Profilo RAG (backend AI + modelli + flag test/fallback)
RAG_PROFILE=ollama-bgem3

# API keys per backend cloud
OPENAI_API_KEY=sk-...
GEMINI_API_KEY=...

# Host Ollama (default: http://localhost:11434)
OLLAMA_HOST=http://localhost:11434
```

### 4.1 Profilo RAG / backend
- `RAG_PROFILE=<nome>` seleziona un preset definito in `config/packages/rag_profiles.yaml`.
- Ogni preset specifica backend (`ollama|openai|gemini`), modelli chat/embedding e flag `test_mode`/`offline_fallback`.
- È possibile forzare un profilo al volo sul comando di indicizzazione (`php bin/console app:index-docs --rag-profile=openai-mini`).

### 4.2 test_mode (nel profilo)
- `true` → la risposta mostrerà estratti chunk (modalità test, no chiamata AI),
- `false` → normale comportamento RAG + LLM.

Configurato in `config/packages/rag_profiles.yaml` → `ai.test_mode`.

### 4.3 offline_fallback (nel profilo)
- `true` → se il backend AI è giù, ELARA restituisce contenuti testuali dai chunk,
- `false` → in caso di problemi del backend AI viene propagato un errore.

Configurato in `config/packages/rag_profiles.yaml` → `ai.offline_fallback`.

---

# 🧵 5. Esempi curl

Di seguito una serie di esempi pratici per interagire con `/api/chat`.

## 5.1 Richiesta base
### curl
```bash
curl -X POST https://127.0.0.1:8000/api/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer {token_generato}" \
  -d '{"question":"Riassumi la pipeline di indicizzazione"}'
```

### Comportamento atteso
- Viene calcolato l’embedding della domanda.
- Vengono recuperati i chunk più simili.
- Viene generata una risposta in linguaggio naturale che descrive il flusso RAG.

## 5.2 Richiesta — modalità TEST attiva

Usare un profilo con `test_mode: true` (es. `offline-test`) oppure il flag CLI:

```bash
php bin/console app:index-docs --test-mode
```

Quindi:

```bash
curl -X POST https://127.0.0.1:8000/api/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer {token_generato}" \
  -d '{"question":"Riassumi la pipeline di indicizzazione"}'
```

### Comportamento atteso
- **Nessuna chiamata** al backend AI.
- L’API restituisce una risposta di tipo
  ```
  [TEST MODE] Ho trovato estratti...
  ```
  con una lista di brevi estratti dei chunk che contengono le parole chiave.

## 5.3 Richiesta con messaggio vuoto (errore)

```bash
curl -X POST https://127.0.0.1:8000/api/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer {token_generato}" \
  -d '{"question":""}'
```

### Risposta attesa

```json
{
  "error": "Messaggio vuoto"
}
```

Codice HTTP: tipicamente `400 Bad Request`.

## 5.4 Esempio con selezione profilo RAG

Eseguendo l’applicazione con, ad esempio:

```env
RAG_PROFILE=ollama-bgem3
```

oppure lanciando `php bin/console app:index-docs --rag-profile=openai-mini`, cambia il backend AI utilizzato (modelli chat/embedding e flag test/fallback) ma:
- l’endpoint rimane identico dal punto di vista dell’utente,
- la qualità/latency dipenderanno dal profilo scelto (Ollama locale vs OpenAI vs Gemini).

La chiamata curl rimane la stessa:

```bash
curl -X POST https://127.0.0.1:8000/api/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer {token_generato}" \
  -d '{"question":"Riassumi la pipeline di indicizzazione"}'
```

---

# 📤 6. Struttura delle risposte

## 6.1 Risposte di successo (modalità normale)

La risposta di successo più semplice è un JSON del tipo:

```json
{
  "answer": "Testo della risposta generata dal modello AI basata sui documenti indicizzati."
}
```

In alcune implementazioni possono essere presenti anche
- informazioni aggiuntive (es. debug, contesto),
- metadati opzionali.

## 6.2 Risposte di errore

In caso di errore di validazione (es. messaggio vuoto):

```json
{
  "error": "Messaggio vuoto"
}
```

Altri possibili errori gestiti:
- backend AI non raggiungibile,
- eccezioni interne (es. problemi di connessione a PostgreSQL),
- errori generici (500).

In presenza di `APP_AI_OFFLINE_FALLBACK=true`, invece di un errore duro si può ricevere una risposta strutturata con estratti dai documenti.

## 6.3 Risposte in modalità TEST

Formato esemplificativo:

```json
{
  "mode": "test",
  "question": "[TEST MODE] Ho trovato estratti nei seguenti chunk:",
  "chunks": [
    "...primo estratto...",
    "...secondo estratto..."
  ]
}
```

Obiettivo: permettere all’utente di verificare *quali parti della documentazione* vengono viste come rilevanti.

## 6.4 Risposte in offline fallback

Esempio:

```json
{
  "mode": "offline_fallback",
  "note": "Backend AI non disponibile, sto restituendo estratti testuali.",
  "chunks": [
    "...contenuto rilevante 1...",
    "...contenuto rilevante 2..."
  ]
}
```

Questa modalità assicura che l’API non sia completamente inutilizzabile in caso di problemi con il modello AI.

---

# 🧾 7. Codici di stato HTTP

Sebbene la logica di mapping possa essere estesa, i casi principali sono:

- `200 OK` → richiesta valida, risposta prodotta (modalità normale, test o fallback).
- `400 Bad Request` → input non valido (ad es. `question` vuoto o mancante).
- `500 Internal Server Error` → eccezioni non gestite correttamente (ad es. problemi con il backend AI senza fallback).

In contesti produttivi è consigliabile:
- loggare le eccezioni lato server,
- restituire messaggi d’errore generici verso il client.

---

# ✅ 8. Best practice per l’utilizzo di `/api/chat`

1. **Sempre inviare `Content-Type: application/json`** nelle richieste.
2. **Sempre inviare `Authorization: Bearer {token_generato}`** nelle richieste
3. **Validare lato client** che `question` non sia vuoto o composto solo da spazi.
4. **Gestire time-out client**: i modelli AI possono impiegare alcuni secondi.
5. **Non abusare della modalità TEST in produzione**: è anzitutto uno strumento di debug.
6. **Gestire le modalità via config/packages/rag_profiles.yaml** (test/fallback/backend) **senza cambiare il codice**.
7. **Loggare le richieste critiche** (ad es. per audit, se i dati sono sensibili).

---

# 🧩 9. Integrazione con altri strumenti

L’endpoint `/api/chat` può essere facilmente integrato con:
- Postman / Bruno / Insomnia (copiando gli esempi curl),
- script Bash o PowerShell per interrogazioni batch,
- applicazioni frontend (React/Vue/Symfony Twig) tramite `fetch`/`axios`,
- altri backend (PHP, Python, Node, ecc.) tramite HTTP client.

La natura JSON dell’API la rende adatta a qualunque ecosistema.

---

# 🧾 10. Cheat sheet rapido

- **Endpoint**: `POST /api/chat` o `POST /api/chat/stream`
- **Richiesta minima**:
  ```json
  { "question": "Domanda dell'utente" }
  ```
- **Header obbligatorio**: `Content-Type: application/json`
- **Header obbligatorio in caso di streaming**: `Authorization: Bearer {token_generato}`
- **Modalità TEST**: `APP_AI_TEST_MODE=true`
- **Offline fallback**: `APP_AI_OFFLINE_FALLBACK=true`
- **Backend AI / preset**: `RAG_PROFILE=<nome>` (es. `ollama-bgem3`, `openai-mini`)

Esempio curl minimal:

```bash
curl -X POST https://127.0.0.1:8000/api/chat \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer {token_generato}" \
  -d '{"question":"Riassumi la pipeline di indicizzazione"}'
```
---
