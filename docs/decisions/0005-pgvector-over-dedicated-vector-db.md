# 0005 — pgvector em vez de vector DB dedicado

- **Status:** Aceito
- **Data:** 2026-05-16

## Contexto

RAG precisa armazenar embeddings (vetores ~1536d) e fazer busca por similaridade cosseno. Opções:
- **pgvector** — extensão Postgres, mesmo banco dos dados de negócio
- **Pinecone** — SaaS gerenciado, pago
- **Qdrant / Weaviate / Milvus** — vector DBs dedicados, self-host

## Decisão

Usar **pgvector** (`pgvector/pgvector:pg16`) no mesmo Postgres dos dados relacionais.

## Consequências

**Positivas:**
- Um único banco simplifica backup, deploy, conexão, transações
- JOINs entre `document_chunks` (vetores) e `documents` (metadata) sem cross-DB
- `workspace_id` em filtro WHERE = isolamento de tenant trivial
- Postgres tem índices HNSW/IVFFlat suficientes pra escala MVP
- Gratuito, self-host nativo

**Negativas:**
- Performance abaixo de Qdrant/Pinecone em escala massiva (>100M vetores)
- Mitigação: index HNSW + particionamento por workspace quando escalar

## Alternativas rejeitadas

- **Pinecone:** custo recorrente, vendor lock, dados saem do self-host
- **Qdrant/Weaviate:** mais um serviço pra operar, JOINs cross-DB chatos, ganho marginal no MVP
- **Embeddings em FTS Postgres:** não faz similaridade vetorial real
