
# ELARA — Analisi Tecnica Completa, Servizi Interni e Configurazioni Avanzate

## 1. Architettura
src/
  AI/
  Command/
  Controller/
  Entity/
  Middleware/
  Repository/
  Service/

Ogni directory ha un ruolo preciso per mantenere isolamento logico e alta manutenibilità.

## 2. Configurazioni
### 2.1 doctrine.yaml
Imposta supporto pgvector:
vector: PhpPgVector\Doctrine\DBAL\Types\VectorType
server_version: 18

### 2.2 services.yaml
Registra:
- AiClientFactory
- OpenAiClient
- OllamaClient
- DocumentTextExtractor
- ChatbotService
- PgvectorIvfflatMiddleware

## 3. DocumentTextExtractor (dettagliato)
### 3.1 Obiettivi
Convertire documenti eterogenei in testo pulito e omogeneo.

### 3.2 Supporto formati
- **PDF:** Smalot PDF Parser, rimozione artefatti grafici, caratteri strani.
- **Markdown:** lettura diretta, rimozione heading/markup.
- **DOCX:** PhpOffice → estrazione paragrafo per paragrafo.
- **ODT:** lettura ZIP, estrazione content.xml.

### 3.3 Normalizzazione
- rimozione multipli spazi
- trim globale
- rimozione newline multipli
- rimozione caratteri invisibili

### 3.4 Rimozione emoji
Regex Unicode su blocchi emoticon, simboli vari, dingbats.

### 3.5 Output ideale
Testo coerente e stabile, ottimizzato per chunking.

## 4. ChatbotService (dettagliato)
### 4.1 Pipeline interna
ASK(question):
  → embed domanda con AiClient
  → retrieval chunk da DocumentChunkRepository
  → costruzione contesto
  → generazione prompt system+user
  → chiamata a client AI
  → ritorno risposta pulita

### 4.2 Retrieval vettoriale
findTopKSimilar() esegue:
ORDER BY embedding <=> :vec LIMIT k

### 4.3 Prompt engineering
System message:
"Rispondi SOLO usando il contesto. Non inventare."

User message contiene:
- contesto
- domanda originale

### 4.4 Gestione fallback
Se non vengono trovati chunk, ChatbotService restituisce risposta neutra:
"Non ho informazioni sufficienti."

## 5. Entity Model
### DocumentFile
UUID, path, hash, createdAt, relazione chunks.

### DocumentChunk
UUID, contenuto, indice, embedding vector(1536), relazione ManyToOne.

## 6. Middleware IVF-FLAT
Ricostruzione indice vettoriale:
USING ivfflat (embedding vector_cosine_ops) WITH (lists=100)

## 7. Repositories
### DocumentChunkRepository
Contiene ricerca vettoriale KNN per pgvector.

## 8. Controller REST
ChatController espone /api/chat e usa ChatbotService senza logica propria.

## 9. .env.local

Database:
- DATABASE_URL

Ollama backend:
- AI_BACKEND
- OLLAMA_HOST
- OLLAMA_CHAT_MODEL
- OLLAMA_EMBED_MODEL

Test mode e Fallback:
- APP_AI_TEST_MODE=true
- APP_AI_OFFLINE_FALLBACK=false

Sonde IVF-FLAT per ricerca vettoriale:
- APP_IVFFLAT_PROBES=10

## 11. Conclusione tecnica
ELARA implementa un motore RAG modulare, estensibile e robusto,
idoneo sia per uso locale (Ollama) sia cloud (OpenAI).

## 11. Cosa manca
1. Autenticazione e Gestione utenti
2. Interfaccia grafica per il chatbot
3. Interfaccia grafica Admin(EasyAdmin) per il caricamento dei file da indicizzare
4. Scheduler per automatizzare l'indicizzazione dei documenti caricati
