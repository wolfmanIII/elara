# Decisioni e TODO operativi

## Cache chatbot
- `APP_CHAT_CACHE_TTL` controlla la cache di risposta/fonti: valore in secondi, 0 disabilita. Chiave include profilo attivo e test_mode.
- Backend attuale: `cache.app` (filesystem di default). Valutare APCu/Redis se servono prestazioni o cache condivisa.

## Gestione profili RAG
- Profili in `config/packages/rag_profiles.yaml` (Ollama, OpenAI, Gemini a 1536 dim, offline-test).
- Switch da UI con pulsanti inline + alert mismatch embedding (comandi suggeriti: reset schema + reindex).

## Indici vettoriali
- HNSW default consigliato; IVF-FLAT solo per dataset enormi con tuning. Mai entrambi sulla stessa colonna.

## Utenti e ruoli
- CLI: `app:user-create` per creare e assegnare ruoli `app:user-role` per aggiungere rimuovere, `ROLE_USER` non viene mai rimosso.
- Token API: crearli/ruotarli dalla sezione API Tokens, revocabili; usarli come `Authorization: Bearer <token>` nelle chiamate protette. CLI: `php bin/console app:api-token:create <email> --label=... --ttl=<ore>` stampa il token in chiaro.

## TODO principali
- Cache embedding domanda (key: profilo+test_mode+hash domanda) con TTL breve. Da rivalutare: oggi l'embedding è veloce e non sembra un collo di bottiglia.
- Scheduler reindex (Messenger) e alert su chunk non cercabili/errori embedding.
- Audit chat (utente, modello, latency, top_k) e comando di regressione RAG.
