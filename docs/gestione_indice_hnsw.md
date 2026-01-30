# Gestione Indice Vettoriale HNSW

## Contesto
L'indice HNSW (`document_chunk_embedding_hnsw`) è un indice vettoriale di pgvector usato per velocizzare le ricerche di similarità sulla tabella `document_chunk`.

**Problema**: Doctrine ORM non supporta nativamente gli indici HNSW. L'attributo `#[ORM\Index]` non permette di specificare:
- Il tipo di indice (`USING hnsw`)
- L'operator class (`vector_cosine_ops`)

Di conseguenza, ogni `doctrine:migrations:diff` generava un `DROP INDEX` indesiderato.

## Soluzione Implementata

### Schema Filter in Doctrine
L'indice è stato escluso dalle migrazioni tramite `schema_filter` in `config/packages/doctrine.yaml`:

```yaml
doctrine:
    dbal:
        schema_filter: ~^(?!document_chunk_embedding_hnsw$)~
```

Questo regex negativo fa sì che Doctrine ignori completamente l'indice durante il diff delle migrazioni.

## Comandi Utili

### Creare l'indice HNSW
```sql
CREATE INDEX document_chunk_embedding_hnsw 
ON document_chunk 
USING hnsw (embedding vector_cosine_ops);
```

Via Symfony:
```bash
php bin/console dbal:run-sql "CREATE INDEX document_chunk_embedding_hnsw ON document_chunk USING hnsw (embedding vector_cosine_ops);"
```

### Verificare esistenza dell'indice
```sql
SELECT indexname, indexdef 
FROM pg_indexes 
WHERE tablename = 'document_chunk' AND indexname LIKE '%hnsw%';
```

### Rimuovere l'indice (se necessario)
```sql
DROP INDEX IF EXISTS document_chunk_embedding_hnsw;
```

## Operator Classes disponibili

| Operator Class | Metrica | Uso |
|----------------|---------|-----|
| `vector_cosine_ops` | Cosine similarity | Default per testi/documenti |
| `vector_l2_ops` | Distanza Euclidea | Embedding numerici |
| `vector_ip_ops` | Inner Product | Embedding normalizzati |

## Riferimenti
- [pgvector HNSW](https://github.com/pgvector/pgvector#hnsw)
- [Doctrine Schema Filter](https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html#schema-filter)
