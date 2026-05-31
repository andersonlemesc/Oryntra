# Fase 10 — RAG Knowledge Base: extração (lib + LLM) e retrieval

**Status:** Entregue em 2026-05-31. Branch `feat/rag-document-extraction`.

## Contexto

O case `DocumentCategory::Knowledge` existia desde a Fase 14.4 como placeholder "AI-only, never sent", mas o pipeline de ingestão/retrieval nunca foi construído. RAG era greenfield: sem pgvector, sem `document_chunks`, sem embeddings.

Dúvida que motivou o redesenho: bibliotecas de extração de PDF são pesadas e falham em PDF escaneado/layout complexo. Decidiu-se por extração **híbrida** (lib + fallback LLM de visão) e por separar a base de conhecimento da mídia enviável.

## Decisões travadas com o usuário

1. **Domínios separados.** `documents` é mídia **enviável** (a IA manda foto/doc ao cliente via `send_document`/`query_documents`); a base de conhecimento RAG é o que a IA **lê**. Tabela `agent_documents` própria + resource Filament "Base de Conhecimento". Resource de mídia renomeada para "Mídias" (label-only) e o case `knowledge` escondido dela via `sendableOptions()` (case mantido como sentinela não-enviável; guard do `SendDocument` e testes preservados).
2. **Pipeline de compute no Python** (regra 6 do AGENTS.md, "Embeddings só no Python"). Laravel é dono de upload/storage (MinIO)/metadados e da persistência no pgvector via Eloquent. Python é stateless: baixa o arquivo por URL interna, devolve chunks + vetores.
3. **Extração PDF híbrida.** `.md`/`.txt`/`.csv` → leitura direta; PDF digital → `pypdf`; escaneado/falha → fallback vision-LLM (`pypdfium2` rasteriza páginas, `pillow` encoda PNG, BYOK multimodal transcreve em markdown). Sem GPU (o trabalho pesado é no LLM remoto); crash controlado por concorrência limitada (job Horizon `rag`), caps (máx 50 páginas, 25MB) e `asyncio.to_thread`.
4. **Embedding BYOK por workspace** reusando `AgentLlmKey`. `embedding_provider/model/dim` gravados por documento. Provider `local` suportado (neutralidade open-source).
5. **Troca de modelo de embedding = reindex total** (vetores de modelos diferentes são incompatíveis). UI mostra modal de confirmação com a contagem de documentos e aviso de custo BYOK antes de reprocessar tudo. Isolamento cross-workspace já garantido por `workspace_id` em toda query.
6. **Schema do vetor (MVP):** coluna `vector` **sem dimensão fixa** + filtro por `embedding_model`. Suporta qualquer provider/dim e a coexistência durante o reindex. Sem índice HNSW no MVP (seq scan dentro do subset workspace-scoped). **Upgrade path:** coluna-por-dim com HNSW quando um workspace cruzar ~50k chunks.

## Arquitetura

**Ingestão:** Filament "Base de Conhecimento" → `agent_documents` (pending) → `IndexKnowledgeDocumentJob` (queue `rag`) → `POST agent-python /internal/rag/ingest` (download → extract → chunk → embed) → Laravel persiste `document_chunks` em transação → `indexed`.

**Retrieval:** tool `search_knowledge_base(query, top_k, tags?)` → `POST /api/internal/agent-tools/search-knowledge-base` (string) → Laravel chama `POST /internal/rag/embed-query` (embeda com a cred do workspace) → SQL pgvector `WHERE workspace_id = ? AND embedding_model = ?` ordenado por `<=>` → hits. Laravel orquestra o embedding da query para não threadar a cred pelo supervisor; embeddings permanecem no Python.

## Arquivos principais

**Laravel:** migrations `enable_pgvector_extension` / `create_agent_documents_table` / `create_document_chunks_table` / `add_embedding_config_to_workspaces_table` (driver-aware: `vector` no pgsql, fallback text no sqlite de teste); `AgentDocument`, `DocumentChunk`, `App\Casts\Embedding`, `App\Enums\AgentDocumentStatus`; `App\Actions\Rag\StoreKnowledgeDocument`, `App\Jobs\Rag\IndexKnowledgeDocumentJob`; `AgentRuntimeClient::ingestKnowledge()` + `embedQuery()`; `NativeTool::SearchKnowledgeBase` + registry; `SearchKnowledgeBase` action/controller/request + rota interna; resource `AgentDocuments/*`; horizon `rag-supervisor`.

**Python:** pacote `oryntra_agent/rag/` (`extract.py`, `chunk.py`, `embed.py`) + router `api/rag.py` (`/internal/rag/ingest`, `/internal/rag/embed-query`); tool `search_knowledge_base` em `tools.py` + builder em `tool_runtime.py`. Deps adicionadas: `pypdfium2`, `pillow`.

## Verificação

- Subir `.md` na "Base de Conhecimento" → `pending`→`indexed`, `chunks_count>0`.
- PDF escaneado → fallback vision-LLM (trace mostra a chamada) e indexa.
- Conversa citando termos do doc → especialista chama `search_knowledge_base`, hits scoped ao workspace.
- Trocar modelo de embedding → modal de custo, docs voltam a `pending`, reindexam.

## Testes

Laravel: `Rag/AgentDocumentSchemaTest`, `Rag/IndexKnowledgeDocumentJobTest`, `Rag/SearchKnowledgeBaseControllerTest`, `Filament/AgentDocumentResourceTest` (31 verdes no conjunto RAG+tools; phpstan limpo). Python: `test_rag.py`, `test_search_knowledge_base.py` (14 verdes; ruff/mypy limpos nos arquivos da fase).

## Deferred

Índice HNSW / coluna-por-dim; reranking cross-encoder; ACL por documento; DOCX além do caminho opcional; reindex automático ao trocar provider (hoje manual via modal).
