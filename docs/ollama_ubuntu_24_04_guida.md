# Installazione e configurazione di Ollama come servizio su Ubuntu 24.04

Questa guida spiega:
- come installare Ollama come servizio systemd
- come abilitare il supporto a Vulkan (temporaneo e permanente)
- come scaricare i modelli (pull)
- come visualizzare i log

---

## 1. Installare Ollama come servizio su Ubuntu 22 | 24

### 1.1 Installazione rapida ufficiale

```bash
curl -fsSL https://ollama.com/install.sh | sh
```

Questo comando:
- installa il binario in `/usr/bin/ollama`
- crea automaticamente il servizio systemd
- abilita l'avvio automatico

---

### 1.2 Verifica che il servizio sia attivo

```bash
systemctl status ollama
```

Per avviarlo manualmente, se necessario:

```bash
sudo systemctl start ollama
```

Per abilitarlo all'avvio:

```bash
sudo systemctl enable ollama
```

---

## 2. Verifica del supporto Vulkan

### 2.1 Installare gli strumenti Vulkan

```bash
sudo apt update
sudo apt install -y vulkan-tools
```

### 2.2 Verifica funzionamento Vulkan

```bash
vulkaninfo | head
```

Se restituisce informazioni sulla GPU, Vulkan è funzionante.

---

## 3. Abilitare Vulkan in modo NON permanente (solo per una sessione)

Questo metodo vale solo se avvii Ollama manualmente da terminale:

```bash
OLLAMA_BACKEND=vulkan OLLAMA_DEBUG=1 ollama serve
```

⚠️ Questo metodo **non vale per il servizio systemd**.

---

## 4. Abilitare Vulkan in modo PERMANENTE (servizio systemd)

### 4.1 Creare l'override del servizio

```bash
sudo systemctl edit ollama
```

### 4.2 Inserire questo contenuto

```ini
[Service]
Environment="OLLAMA_BACKEND=vulkan"
Environment="OLLAMA_DEBUG=1"
```

Salva e chiudi.

---

### 4.3 Ricaricare e riavviare il servizio

```bash
sudo systemctl daemon-reload
sudo systemctl restart ollama
```

---

### 4.4 Verifica a runtime

```bash
sudo systemctl show ollama | grep OLLAMA
```

Output atteso:

```
Environment=OLLAMA_BACKEND=vulkan OLLAMA_DEBUG=1
```

---

## 5. Riepilogo comandi per scaricare i modelli (pull)

### 5.1 Elenco modelli disponibili

```bash
ollama list
```

### 5.2 Scaricare un modello

```bash
ollama pull llama3.1:8b
```

```bash
ollama pull bge-m3
```

### 5.3 Avviare un modello

```bash
ollama run llama3.1:8b
```

---

## 6. Visualizzare i log di Ollama (servizio systemd)

### 6.1 Log in tempo reale (live)

```bash
journalctl -u ollama -f
```

---

### 6.2 Log completi

```bash
journalctl -u ollama --no-pager
```

---

### 6.3 Log delle ultime 2 ore

```bash
journalctl -u ollama --since "2 hours ago"
```

---

### 6.4 Solo errori

```bash
journalctl -u ollama -p err --no-pager
```

---

## 7. Percorso dati locali (modelli e cache)

```bash
~/.ollama/
```

Contiene:
- modelli scaricati
- cache
- configurazioni

⚠️ I log NON vengono salvati su file: sono solo nel journal systemd.

---

---

## 8. Abilitare CUDA (NVIDIA) in modo PERMANENTE

⚠️ Su GPU **NVIDIA**, **CUDA** offre prestazioni più alte rispetto a **Vulkan**.

### 8.1 Verifica driver NVIDIA

```bash
nvidia-smi
```

Se il comando non esiste:

```bash
sudo apt install -y nvidia-driver-535
sudo reboot
```

---

### 8.2 Impostare CUDA nel servizio systemd

```bash
sudo systemctl edit ollama
```

Inserisci:

```ini
[Service]
Environment="OLLAMA_BACKEND=cuda"
Environment="OLLAMA_DEBUG=1"
```

---

### 8.3 Riavvio servizio

```bash
sudo systemctl daemon-reload
sudo systemctl restart ollama
```

---

### 8.4 Verifica runtime CUDA

```bash
journalctl -u ollama | grep -i cuda
```

---

## 9. Troubleshooting GPU e Backend

### 9.1 Ollama ignora Vulkan/CUDA

Controlla variabili:

```bash
sudo systemctl show ollama | grep OLLAMA
```

Se vuoto, l'override non è attivo.

---

### 9.2 Vulkan non rileva la GPU

Verifica:

```bash
vulkaninfo | grep deviceName
```

Se non compare la GPU:
- driver non installati
- GPU non compatibile Vulkan

---

### 9.3 CUDA non viene usato

Verifica che Ollama veda CUDA:

```bash
journalctl -u ollama | grep -i "cuda"
```

Se non compare:
- driver errati
- modulo kernel non caricato

---

### 9.4 Verifica carico GPU durante inferenza

Per NVIDIA:

```bash
watch -n 1 nvidia-smi
```

Se la GPU resta a 0%, Ollama sta usando CPU.

---

### 9.5 Ripristino backend automático (CPU)

Per tornare al comportamento standard:

```bash
sudo systemctl edit ollama
```

Rimuovi tutte le righe `Environment=`, salva e poi:

```bash
sudo systemctl daemon-reload
sudo systemctl restart ollama
```

---

## 10. Riepilogo backend per tipo di GPU

| Tipo GPU  | Backend |
|-----------|---------------------|
| NVIDIA    | cuda ✅              |
| AMD       | vulkan ✅            |
| Intel     | vulkan ✅            |
| Nessuna   | cpu                  |

---
