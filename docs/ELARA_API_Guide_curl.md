# ELARA — API Guide per /api/chat con esempi curl

## Endpoint principale
POST /api/chat  
Corpo JSON:
```json
{ "message": "testo domanda" }
```

## Esempio curl — modalità normale
```bash
curl -X POST http://localhost:8000/api/chat   -H "Content-Type: application/json"   -d '{"message":"Riassumi Helix"}'
```

## Esempio — modalità TEST
```bash
curl -X POST http://localhost:8000/api/chat   -H "Content-Type: application/json"   -d '{"message":"helix artefatto iuno"}'
```
Risposta:
```
[TEST MODE] Ho trovato estratti...
```

## Risposte errore
Messaggio vuoto:
```json
{ "error": "Messaggio vuoto" }
```

## ENV rilevanti
```
AI_BACKEND=ollama|openai
APP_AI_TEST_MODE=true|false
APP_AI_OFFLINE_FALLBACK=true|false
```

## Funzionamento
ChatController → ChatbotService → AiClient → pgvector → risposta JSON.
