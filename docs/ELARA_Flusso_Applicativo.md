
# ELARA — Analisi Completa del Flusso Applicativo e dei Command

## 1. Introduzione
ELARA è un motore RAG scritto in Symfony 7.3 che indicizza documenti, genera embedding,
li memorizza in PostgreSQL con pgvector e fornisce risposte tramite API REST usando retrieval aumentato.

Il flusso segue la pipeline:
FILE → Estrattore → Chunking → Embedding → DB → IVF-FLAT → Retrieval → Prompt → AI → Risposta

## 2. Pipeline di Indicizzazione (IndexDocsCommand)
### 2.1 Individuazione file
Il command `app:index-docs` accetta un percorso singolo o una directory.
Per ogni file calcola SHA1 e crea o aggiorna un DocumentFile.

### 2.2 Estrattore testo (DocumentTextExtractor)
Supporta PDF, MD, DOCX, ODT.
Rimuove emoji, normalizza whitespace, converte contenuto in testo lineare.

### 2.3 Eliminazione chunk vecchi
Per ogni DocumentFile viene cancellata la vecchia lista chunk,
garantendo che ogni documento contenga solo informazioni aggiornate.

### 2.4 Chunking
Il testo viene diviso in blocchi (800–1200 caratteri).
Ogni chunk:
- contiene testo normalizzato,
- indice progressivo,
- referenza al DocumentFile.

### 2.5 Embedding
Ogni chunk viene embeddato tramite AiClientInterface (OpenAI o Ollama),
che restituisce un vettore float[] poi salvato nel campo `embedding vector(1536)`.

### 2.6 Persistenza
Ogni chunk viene salvato con relazione ManyToOne verso DocumentFile.

### 2.7 IVF-FLAT Index
Il middleware PgvectorIvfflatMiddleware ricostruisce l’indice ivfflat
per ottimizzare la ricerca vettoriale.

## 3. Funzionamento dei Command
### 3.1 app:index-docs
Ricrea completamente l’indicizzazione del contenuto locale.

### 3.2 app:list-docs
Mostra:
- path
- hash
- numero chunk
- data indicizzazione

### 3.3 app:unindex-file
Rimuove un DocumentFile + chunk associati, sfruttando cascata Doctrine.

## 4. Flusso API Chat
### 4.1 ChatController
Riceve `POST /api/chat`, valida input e passa la domanda a ChatbotService.

### 4.2 ChatbotService (visione generale)
1. embed della domanda
2. ricerca chunk rilevanti con ricerca vettoriale cosine
3. costruzione del contesto
4. creazione di un prompt RAG
5. chiamata a OpenAI/Ollama
6. pulizia e restituzione della risposta

## 5. Diagramma riassuntivo
Estrattore → Chunker → Embedding → DB → IVF-FLAT → Retrieval → AI → Risposta
