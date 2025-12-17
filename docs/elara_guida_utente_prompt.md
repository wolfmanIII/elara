# 🌌 Guida Utente — Come fare le domande giuste a ELARA  
*(per utenti non tecnici)*

ELARA è un assistente che risponde **basandosi esclusivamente sui documenti indicizzati**, non sulla sua “creatività”.  
Per ottenere risposte utili è fondamentale formulare domande efficaci.

> **Regola d’oro:** più la domanda è chiara, più ELARA trova i chunk corretti nei documenti.

---

# 1. 🎯 Come funziona in breve (per capire come chiedere meglio)

Quando scrivi una domanda, ELARA:

1. **Calcola l’embedding della domanda** — la trasforma in una serie di numeri che rappresentano il significato della frase.
2. **Cerca nei documenti i pezzi più simili semanticamente** usando la ricerca vettoriale e la *cosine distance*.
3. **Costruisce un contesto** dai chunk trovati.
4. **Risponde usando solo quel contesto** (RAG: Retrieval-Augmented Generation).

👉 Se la domanda è vaga o troppo generica, l’embedding non “punta” al contenuto giusto e la risposta peggiora.

---

# 2. ✔️ Domande efficaci: struttura consigliata

Le domande migliori seguono uno di questi schemi:

### **A) Domanda diretta e specifica**
> *“Come funziona la pipeline di indicizzazione dei documenti in ELARA?”*

Perché funziona: è un concetto presente nei documenti, quindi ELARA lo recupera correttamente.

---

### **B) Domanda con contesto esplicito**
> *“Nei documenti su ELARA, cosa significa embedding della domanda e come viene usato nel retrieval?”*

---

### **C) Domanda procedurale**
> *“Quali sono i passaggi per eseguire un’indicizzazione completa con app:index-docs?”*

---

### **D) Domanda comparativa**
> *“Qual è la differenza tra modalità test e modalità normale in ELARA?”*

---

# 3. ❌ Domande da evitare (e come migliorarle)

### **1) Domande troppo generiche**
> “Parlami di ELARA.”

🟥 Problema: troppo vaga.

🟩 Meglio:
> “Puoi spiegare l’architettura complessiva di ELARA descritta nei documenti?”

---

### **2) Domande non correlate al materiale**
> “Come faccio a progettare un modello AI?”

ELARA risponde solo usando i documenti indicizzati.

---

### **3) Domande troppo brevi**
> “Indicizzazione?”

🟩 Meglio:
> “Come funziona il processo di indicizzazione dei documenti in ELARA?”

---

### **4) Domande che chiedono opinioni non supportate**
> “Qual è il miglior modello AI del 2025?”

ELARA può rispondere solo con ciò che è presente nei documenti.

---

# 4. 🎒 Template consigliati per domande “perfette”

### **Template 1 — Per capire un concetto**
> *“Nei documenti caricati, come viene definito **{concetto}** e qual è il suo ruolo nel flusso RAG?”*

---

### **Template 2 — Per chiedere una procedura**
> *“Quali sono i passaggi descritti nei documenti per eseguire **{procedura}**?”*

---

### **Template 3 — Per chiedere un riassunto mirato**
> *“Puoi riassumere ciò che i documenti dicono riguardo **{argomento}** mantenendo solo le informazioni essenziali?”*

---

### **Template 4 — Per chiarimenti o approfondimenti**
> *“Secondo quanto riportato nei documenti, quali sono i vantaggi di usare embedding di dimensione 1024?”*

---

# 5. 🧪 Come testare correttamente il sistema

Posso verificare la qualità di una domanda usando la **Modalità TEST**:
- non chiama l’AI
- mostra i chunk trovati
- permette di capire se la domanda è formulata bene

Se i chunk trovati non sono pertinenti → riformula la domanda.

---

# 6. 🟦 FAQ rapide per l’utente finale

### **ELARA non risponde come mi aspettavo.**
✔️ Rendi la domanda più specifica.  
✔️ Usa termini presenti nei documenti.

---

### **ELARA dice che l’informazione non è disponibile.**
Significa che non esiste nei documenti indicizzati.

---

### **Posso fare domande lunghe?**
Sì. L’embedding ragiona sul significato, non sulle singole parole.

---

# 7. 📌 Esempi pratici

### ❌ Domanda poco utile
> “Cos’è un indice?”

### ✔️ Domanda efficace
> “Cosa si intende per indice HNSW e perché è consigliato rispetto a IVF-Flat?”

---

### ❌ Domanda vaga
> “Come funziona ELARA?”

### ✔️ Domanda efficace
> “Puoi spiegare il flusso FILE → Estrattore → Chunking → Embedding → Retrieval → Risposta?”

---

# 8. 🎁 Suggerimento finale

> Formulo la domanda come se stessi chiedendo a un collega: chiara, mirata e contestualizzata.

---

# 9. 📚 Frase riassuntiva

> **ELARA risponde bene quando dico chiaramente *di cosa sto parlando*.**
