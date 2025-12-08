# ELARA — Presentazione Tecnica Moderna

Questa presentazione fornisce una panoramica chiara, moderna ed essenziale del motore RAG **ELARA**, spiegandone l’architettura, il flusso applicativo, i componenti principali e i vantaggi.

---

# 🚀 1. Cos’è ELARA
**ELARA è un motore RAG (Retrieval‑Augmented Generation)** progettato per:
- indicizzare documenti (PDF, MD, DOCX, ODT),
- estrarre testo e pulirlo,
- generare embedding vettoriali,
- salvare tutto su PostgreSQL tramite pgvector,
- recuperare i chunk più simili alla domanda,
- costruire un contesto RAG,
- generare risposte precise e basate solo sui documenti.

Obiettivo: **consultare la documentazione in linguaggio naturale**.

---

# 🧩 2. Architettura ad alto livello
```
FILE → Estrattore → Chunking → Embedding → PostgreSQL
      → Indice Vettoriale (HNSW/IVF-FLAT)
      → /api/chat → embedding domanda → retrieval → contesto
      → modello AI (Ollama/OpenAI) → risposta JSON
```

Componenti principali:
- DocumentTextExtractor
- ChunkingService
- AiClientInterface
- DocumentChunkRepository
- ChatbotService
- pgvector
- API REST /api/chat

---

# 📄 3. Pipeline di indicizzazione
1. Caricamento file
2. Hash + creazione DocumentFile
3. Estrazione testo
4. Normalizzazione
5. Chunking avanzato
6. Embedding dei chunk
7. Persistenza
8. Creazione indici vettoriali

Risultato: una base di conoscenza interrogabile via embedding.

---

# 🧠 4. Retrieval & Risposta
### Retrieval
- embedding della domanda,
- ricerca cosine similarity,
- selezione top_k chunk,
- costruzione contesto.

### Risposta
- prompt RAG con contesto,
- modello AI (Ollama/OpenAI),
- risposta deterministica basata sui documenti.

---

# 🔌 5. Backend AI intercambiabile
Configurabile via ENV:
```
AI_BACKEND=ollama|openai
```
### Ollama
- locale
- nessun costo
- modelli 7B/8B

### OpenAI
- qualità più alta
- embedding 1536

---

# 🌐 6. API /api/chat
Richiesta:
```json
{ "message": "La tua domanda" }
```
Modalità supportate:
- normale
- TEST mode
- offline fallback

Risposta:
```json
{ "answer": "Risposta basata sui documenti" }
```

---

# 🏗️ 7. Stack Tecnologico
- **Symfony 7.3** (backend)
- **Doctrine ORM**
- **PostgreSQL + pgvector**
- **Ollama / OpenAI**
- **Chunking intelligente**

---

# ⭐ 8. Vantaggi di ELARA
- Risposte basate esclusivamente sui documenti
- Zero hallucination (grazie a prompt rigido)
- Backend AI sostituibile
- Database vettoriale locale
- Facilmente estendibile
- Perfetto per documentazione tecnica interna

---

# 📊 9. Use cases
- Manuali tecnici
- Documentazione interna
- Knowledge base
- Supporto clienti
- FAQ dinamiche
- Reparti aziendali con grandi archivi PDF

---

# 🔮 10. Evoluzioni future
- Interfaccia web completa
- Dashboard caricamento documenti
- Ruoli e permessi
- Multi‑utente
- Versionamento avanzato documenti

---