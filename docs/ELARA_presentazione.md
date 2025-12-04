# 1. Cos’è un sistema RAG, in parole semplici
Quando parliamo di RAG, parliamo di Retrieval-Augmented Generation.
Detto in modo meno tecnico: è un sistema che unisce due mondi:
* da una parte un motore di ricerca intelligente sui tuoi documenti
* dall’altra un assistente tipo chatbot che sa scrivere risposte in linguaggio naturale

Il flusso, semplificando, è questo:
1. Raccolta delle fonti  
Carichiamo file, manuali, PDF, documentazione interna. Il sistema li legge, li pulisce, li spezzetta in piccoli pezzi di testo gestibili (i “chunk”).

2. Indicizzazione intelligente  
Ogni pezzo di testo viene trasformato in una rappresentazione numerica che ne cattura il significato (gli embedding, ci torno tra poco). Questi numeri vengono salvati in un database speciale, che permette di confrontare “quanto due testi si somigliano” invece di limitarsi alle parole esatte.

3. Quando l’utente fa una domanda  
Anche la domanda viene trasformata nello stesso tipo di numeri. Il sistema cerca, tra tutti i pezzi di testo indicizzati, quelli che “somigliano di più” alla domanda, cioè che parlano più o meno delle stesse cose.

4. Risposta del chatbot  
Solo a questo punto entra in gioco il modello di AI generativa: gli passiamo la domanda + i pezzi di documentazione più rilevanti e gli chiediamo:
“Rispondi usando solo queste informazioni, senza inventare.”

Il vantaggio rispetto a un “chatbot generico” è che:
* risponde in base alle tue fonti interne
* può citare o riassumere documenti aziendali
* riduce molto il rischio di risposte inventate (hallucination)

In pratica RAG significa: “AI che risponde, ma con il naso dentro i tuoi documenti, non solo nella sua memoria addestrata.”
# 2. Lo Scopo di ELARA
ELARA è il nome che abbiamo dato al motore RAG interno:
E.L.A.R.A. — Embedding Linking & Retrieval Answering.

Il suo Scopo è molto chiara:

*Permettere a chiunque, senza competenze tecniche, di interrogare la documentazione disponibile semplicemente parlando con un chatbot.*

Dal punto di vista di chi lo usa, l’esperienza ideale è:
* apro un’interfaccia (domani web, oggi già via API)
* scrivo una domanda in italiano normale
* ottengo una risposta che:
* è leggibile
* è basata sui nostri documenti
* e, quando serve, mi indica anche da dove arrivano le informazioni

Dietro le quinte ELARA fa un lavoro piuttosto sofisticato, ma nascosto all’utente:
* Indicizza i documenti che mettiamo in una cartella dedicata (/var/knowledge)
* Li converte in testo pulito, rimuovendo formattazioni strane, emoji e rumore
* Li spezza in “chunk” e genera per ognuno l’embedding, cioè la rappresentazione numerica del significato
* Salva tutto in PostgreSQL, usando l’estensione pgvector e un indice IVF-FLAT per mantenere le ricerche veloci anche con molti documenti
* Quando arriva una domanda via API /api/chat, la passa al ChatbotService, che:
* crea l’embedding della domanda
* cerca i chunk più simili
* costruisce un contesto
* chiama il modello AI (Ollama o OpenAI)
* restituisce una risposta JSON pulita

La filosofia di ELARA è:
* semplicità d’uso: dall’esterno è solo “POST /api/chat con un messaggio”
* flessibilità: si può cambiare backend AI (locale con Ollama, cloud con OpenAI) via configurazione, senza toccare il codice applicativo
* controllo: l’indice è nel nostro database, i flussi sono chiari e documentati, e possiamo decidere cosa indicizzare e cosa no.
# 3. Cosa si intende per “embedding del testo”
Adesso arriviamo al concetto chiave: embedding.

Immagina di prendere una frase, ad esempio:

> “Come funziona l’indicizzazione dei documenti in ELARA?”

Un modello di AI non memorizza questa frase come stringa di caratteri, ma la traduce in una lunga lista di numeri, ad esempio 1024 numeri decimali.  
Questa lista di numeri è l’embedding della frase.

Cosa rappresenta?
* Ogni testo diventa un “punto” in uno spazio matematico ad alta dimensione
* Testi che parlano di cose simili finiscono vicini
* Testi che parlano di argomenti diversi finiscono lontani

È un po’ come creare una mappa invisibile delle idee: invece di guardare alle parole precise, guardiamo alla somiglianza di significato.

Nel nostro caso:
* quando indicizziamo i documenti, per ogni chunk salviamo:
  * il testo
  * il file di origine
  * e il suo embedding in una colonna vector(1024) di PostgreSQL
* quando arriva una domanda:
  * creiamo l’embedding della domanda
  * chiediamo al database: “Quali chunk hanno un embedding più vicino a questo?”
 
 In pratica, embedding del testo = “tradurre le frasi in numeri in modo che la distanza tra i numeri rispecchi la vicinanza di significato tra i testi”.
# 4. Come lavorano insieme PostgreSQL, pgvector, IVF-FLAT e Doctrine
Qui entriamo un po’ più nel tecnico, ma sempre a livello concettuale.

## PostgreSQL
È il nostro database relazionale “classico”: tabelle, righe, colonne.

pgvector [Neon Docs](https://neon.com/docs/extensions/pgvector)

È un’estensione di PostgreSQL che aggiunge:
* un tipo di colonna vector(N), cioè un array di N numeri
* operatori e funzioni per confrontare vettori (distanze, similarità)
* indici speciali per rendere queste ricerche molto più veloci

Nel nostro schema:
* la entity DocumentChunk di Doctrine ha una proprietà embedding mappata come tipo vector con lunghezza 1024
* questo campo è “il posto” dove salviamo l’embedding di ogni pezzo di testo

**Doctrine (in Symfony)**
Doctrine è lo strato che collega il mondo PHP/oggetti alle tabelle Postgres.
Per ELARA:
* definiamo le entity DocumentFile e DocumentChunk
* configuriamo il tipo custom vector per pgvector
* registriamo funzioni DQL come cosine_similarity per poter scrivere query “ad alto livello” che sotto si traducono in SQL con gli operatori vettoriali di pgvector

**IVF-FLAT** [Google Cloud](https://cloud.google.com/blog/products/databases/faster-similarity-search-performance-with-pgvector-indexes)

IVF-FLAT è un tipo di indice per ricerche “per somiglianza” su vettori:
* invece di confrontare ogni vettore con tutti gli altri (lentissimo su tante righe)
* crea dei cluster di vettori (liste invertite) attorno a dei “centri”
* quando fai una ricerca:
  * trova i cluster più vicini al vettore della domanda
  * cerca solo dentro quei cluster, non su tutta la tabella

È un po’ come dividere una biblioteca in sezioni: se cerco un libro di informatica, non vado a controllare gli scaffali di cucina.

Nel nostro progetto:
* creiamo un indice IVF-FLAT sulla colonna embedding della tabella document_chunk
* usiamo un middleware (PgvectorIvfflatMiddleware) per impostare il numero di “sonde” (quanti cluster visitare), bilanciando velocità e precisione

Il flusso completo
1. Indicizzazione (command app:index-docs)
   * DocumentTextExtractor estrae il testo dai file (PDF, MD, DOCX, ODT)
   * il testo viene ripulito e spezzato in chunk
   * per ogni chunk chiamiamo l’AiClient per ottenere l’embedding
   * Doctrine salva i chunk con il loro embedding in PostgreSQL
   * l’indice IVF-FLAT viene creato/aggiornato
2. Domanda utente (API /api/chat)
   * ChatbotService crea l’embedding della domanda
   * con il repository DocumentChunkRepository esegue una query “top K simili”
   * la query ordina i chunk in base alla distanza vettoriale (cosine)
   * i primi N chunk formano il contesto da passare al modello AI
   * l’AI genera la risposta, che viene restituita in JSON
# 5. L’operatore vettoriale <=> di Postgres/pgvector
L’operatore <=> è una delle “magie” che pgvector aggiunge a PostgreSQL.

In pratica:
* prende due vettori come input (ad esempio: embedding del chunk, embedding della domanda)
* calcola la cosine distance, cioè una misura di quanto i due vettori puntano nella stessa direzione

Tradotta in parole:
* ogni embedding è come una freccia in uno spazio astratto
* se le frecce puntano nella stessa direzione → testi con significato simile
* se puntano in direzioni molto diverse → testi che parlano d’altro

L’operatore <=> restituisce un numero:
* più è piccolo, più i testi sono simili
* più è grande, più sono diversi

Esempio tipico di query:
```sql
SELECT id, contenuto
FROM document_chunk
ORDER BY embedding <=> :embedding_domanda
LIMIT 5;
```
Questa query dice al database:

> “Dammi i 5 pezzi di testo il cui significato è più vicino al significato della domanda.”

L’indice IVF-FLAT accelera proprio questa operazione: invece di confrontare la domanda con tutti i chunk, usa una struttura a cluster per arrivare rapidamente ai vettori più vicini. 

Nel codice di ELARA, quando usiamo la funzione cosine_similarity in DQL, sotto sotto Doctrine traduce in SQL usando gli operatori e le funzioni di pgvector; il concetto però è esattamente questo: ordinare i chunk dal più simile al meno simile in base a <=> / cosine.
## Perché un embedding di 1536 dimensioni? Perché è così importante?
Quando diciamo che un embedding è composto da 1536 numeri, può sembrare un dettaglio arbitrario o puramente tecnico.
In realtà è una scelta fondamentale per la qualità delle risposte del motore RAG, e vale la pena spiegarlo in modo semplice.
### 1. Ogni numero rappresenta una “sfumatura di significato”
Un embedding è una rappresentazione matematica del significato di un testo.  
Più dimensioni ci sono, più dettagli riesce a catturare.
* Con poche dimensioni (ad esempio 50 o 100), il modello riesce a “capire” solo concetti molto generici.
* Con molte dimensioni (oltre 1000), riesce a distinguere concetti simili, contesti, ruoli, gerarchie, relazioni, sinonimi.

Immagina lo spazio dei significati come una stanza:
* poche dimensioni = stanza piccola, dove gli oggetti si schiacciano uno contro l’altro
* 1536 dimensioni = una “stanza” grandissima, dove ogni concetto può trovare la sua posizione precisa
### 2. 1536 è la dimensione ottimale dei modelli embedding moderni
Molti dei migliori modelli di embedding attuali — come OpenAI text-embedding-3-small o analoghi modelli open-source — generano vettori proprio di 1536 dimensioni.

Perché?
* È un compromesso eccellente tra precisione semantica e costi computazionali.
* Con dimensioni inferiori gli embedding diventano meno precisi e peggiora la ricerca dei chunk pertinenti.
* Con dimensioni molto superiori aumentano i costi, il peso su PostgreSQL e i tempi di ricerca, senza un grande miglioramento in qualità.

1536 è, nella pratica, lo standard attuale per un embedding general-purpose ad alta qualità.
### 3. Garantisce recuperi molto più accurati

Ricordiamo che lo scopo principale dell’embedding è trovare i pezzi di testo più simili alla domanda.  
Con un embedding ad alta dimensionalità:
* due testi simili finiscono molto vicini nello spazio vettoriale
* due testi diversi sono chiaramente separati

Questo si traduce nel fatto che ELARA recupera chunk più pertinenti e quindi produce risposte più utili e meno generiche.

Un embedding piccolo, invece, tenderebbe a confondere concetti diversi:
* “procedura di sicurezza” somiglierebbe troppo a “procedura operativa”
* “attivazione API” sarebbe confusa con “attivazione operatore”
* “utente amministratore” risulterebbe simile a “utente generico”
Con 1536 dimensioni invece queste sfumature vengono mantenute e separate.
### 4. È la dimensione ideale per l’operatore vettoriale <=> e gli indici IVF-FLAT
L’accuratezza del confronto vettoriale dipende molto dalla qualità degli embedding:
* l’operatore <=> (cosine distance) funziona meglio su spazi ricchi e ben rappresentati
* l’indice IVF-FLAT riesce a clusterizzare in modo efficace solo se i vettori hanno sufficiente dimensionalità
* Doctrine, pgvector e PostgreSQL lavorano in modo ottimale con questi vettori “densi” ad alta qualità

In pratica:
> più qualità nell’embedding = meno errori nella ricerca dei chunk = risposte migliori.
### 5. È uno standard ben supportato e pensato per la compatibilità futura
Scegliere una dimensione standard come 1536 significa:
* poter cambiare modello embedding in futuro senza cambiare database
* compatibilità con centinaia di modelli attuali e futuri
* stabilità dell’indice pgvector/IVF-FLAT
* pieno supporto da parte delle librerie AI usate da ELARA (sia OpenAI che Ollama)
In altre parole: è una scelta che protegge l’investimento tecnico e mantiene il sistema flessibile nel tempo.

### In sintesi(cosa avrei voluto)

Ecco una frase sintetica che puoi usare a voce:
> *Abbiamo scelto embedding a 1536 dimensioni perché sono lo standard moderno per ottenere una rappresentazione molto precisa del significato del testo. Più dimensioni significano più sfumature, più accuratezza nella ricerca dei chunk e risposte migliori del chatbot. È una scelta che massimizza la qualità ma mantiene il sistema veloce, stabile e compatibile con i modelli attuali e futuri.*

# 6. Ordine di presentazione dei documenti
1. ELARA_Flusso_Applicativo.md
   * Per iniziare dal quadro generale: 
  “FILE → Estrattore → Chunking → Embedding → DB → IVF-FLAT → Retrieval → Prompt → AI → Risposta”
   * Qui spiego la pipeline end-to-end e i command principali (app:index-docs, app:list-docs, app:unindex-file).

2. ELARA_Flusso_Servizi_Dettagliato.md
   * Secondo step: zoom sui servizi core, soprattutto ChatbotService e DocumentTextExtractor.
   * Qui posso far vedere come la domanda passa attraverso ask(), come viene costruito il contesto e come funziona la modalità test/offline.

3. ELARA_Analisi_Tecnica.md
   * Terzo step: entra nell’architettura applicativa:
     * directory src/AI, Service, Repository, Middleware
     * configurazioni Doctrine/pgvector, tipi vector, middleware IVF-FLAT
     * entity DocumentFile e DocumentChunk
   * È il pezzo “da sviluppatori”.

4. ELARA_API_Guide_curl.md
   * Quarto step: la parte “pratica” per chi deve integrare ELARA:
     * endpoint /api/chat
     * esempi curl
     * gestione degli errori e variabili ENV per test mode / fallback.
   * Qui faccio quanto è semplice utilizzare il servizio dal punto di vista di un’app esterna.

5. README.md
   * Alla fine, come “landing page” e riepilogo:
     * definizione di ELARA
     * dipendenze principali
     * setup PostgreSQL + pgvector + indice ivfflat
     * comandi per indicizzare, elencare e rimuovere file
   * Ciò che chi vuole provarlo deve seguire.
# 7. Conclusioni: perché conviene un motore RAG interno

Chiudo con qualche punto che puoi usare nelle conclusioni:
1. Conoscenza accumulata davvero sfruttata
    I documenti non restano “morti” in una cartella o in un wiki: diventano una base di conoscenza a cui si accede in linguaggio naturale, via chatbot.
2. Risposte contestualizzate e aggiornabili
    Se si aggiorna una procedura o un manuale e re-indicizzi i documenti, il motore RAG inizierà a rispondere usando le informazioni nuove, senza dover ri-addestrare un modello.
3. Controllo su dati e fonti
    Gli embedding e i testi nel PostgreSQL in locale, con pgvector e gli indici configurati e personalizzati. Si può decidere cosa indicizzare, cosa escludere, come strutturare gli accessi e in futuro agganciare autenticazione e ruoli.
4. Privacy e conformità
    Si possono usare backend locali (Ollama) per evitare che i documenti escano all'esterno, oppure usare OpenAI solo per la parte di generazione, mantenendo comunque in casa l’indice vettoriale e i dati sensibili.
5. Integrazione con lo stack esistente
    ELARA è scritto in Symfony, usa Doctrine e PostgreSQL: si integra bene con applicazioni già esistenti.
6. Scalabilità graduale
    Si parte da pochi documenti, cresce nel tempo; gli indici vettoriali come IVF-FLAT e le sonde configurabili permettono di bilanciare prestazioni e qualità senza stravolgere l’architettura.

In sintesi:  
un motore RAG interno come ELARA trasforma la documentazione in un assistente consultabile in linguaggio naturale, mantenendo però controllo, trasparenza e integrazione con l’infrastruttura esistente.
