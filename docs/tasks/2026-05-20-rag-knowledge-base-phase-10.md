# RAG Knowledge Base Phase 10 Implementation Plan

> **For agentic workers:** Use checkbox (`- [ ]`) syntax for tracking. Implement task-by-task.

**Goal:** Give Oryntra specialists the ability to answer questions using a workspace-scoped document library. Admins upload PDFs/DOCX/MD, the system indexes them into pgvector, and a native `search_knowledge_base` tool retrieves the top relevant chunks during a conversation.

**Architecture:** Laravel owns the entire knowledge pipeline. Documents live in MinIO. Embeddings live in Postgres via pgvector. Python only calls a Laravel gateway tool with a query string — never touches the DB or storage directly. Tenancy is enforced on every read and every embedding write.

**Tech Stack:** Laravel 13, PHP 8.4, Postgres + pgvector, Filament 5, Horizon, MinIO, OpenAI embeddings (configurable provider), Pest.

---

## Current State

- `agent_runs`, `agent_specialists`, `agent_chatwoot_bindings` already use jsonb config and are workspace-scoped.
- `Agent.rag_config` jsonb exists in the form (enabled / top_k / min_score / answer_only_with_context) but has no implementation behind it.
- `NativeToolRegistry` exposes Chatwoot tools but no RAG tool yet.
- pgvector is **not installed** yet (must be confirmed before Task 1).
- MinIO bucket setup is not in scope for this phase if a storage disk like `s3`/`minio` is already configured. If not, this phase uses a local `documents` disk as a fallback.

## Out Of Scope For This Phase

- Reranking (cross-encoder reranker after vector retrieval). The plan uses pure vector similarity.
- Multi-modal documents (images inside PDFs are ignored).
- Document-level access control (we only scope per workspace + optional tags).
- Audio/video transcription (lives in the next phase).
- Reranking by recency or freshness.

## Data Model

### `agent_documents`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `workspace_id` | bigint fk cascade | Tenancy |
| `name` | text | Filename or human-friendly title |
| `description` | text nullable | Admin description |
| `mime_type` | text | application/pdf, text/markdown, etc. |
| `size_bytes` | bigint | |
| `storage_disk` | text | `documents` (default) |
| `storage_path` | text | Path within the disk |
| `checksum` | text | sha256 |
| `tags` | jsonb | Free-form tags for scoping retrieval |
| `index_status` | text | `pending`, `indexing`, `indexed`, `failed` |
| `index_error` | text nullable | Last failure message |
| `indexed_at` | timestamptz nullable | |
| `chunks_count` | int default 0 | |
| `created_at`, `updated_at` | timestamps | |

Constraints:
- pg check: `index_status IN ('pending','indexing','indexed','failed')`
- index `(workspace_id, index_status)`
- index `(workspace_id, name)`

### `document_chunks`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `workspace_id` | bigint fk cascade | Redundant with `document_id` but kept for query speed and isolation |
| `document_id` | bigint fk cascade | |
| `chunk_index` | int | 0-based position within the document |
| `content` | text | Plain text of the chunk |
| `tokens` | int nullable | Approximate token count |
| `embedding` | vector(1536) | OpenAI text-embedding-3-small default |
| `metadata` | jsonb | source page, section heading, etc. |
| `created_at` | timestamp | |

Constraints:
- unique `(document_id, chunk_index)`
- IVFFlat index on `embedding` with `vector_cosine_ops` (created in a follow-up migration once a few thousand rows exist; in this phase create only the column + btree on document_id).

### `agents.rag_config`

Already exists. We will only consume `enabled`, `top_k`, `min_score`, `answer_only_with_context`, plus a new optional `tags` array used to filter retrieval.

## Task 1: pgvector Extension And Migrations

**Files:**
- Create: `laravel/database/migrations/YYYY_MM_DD_HHMMSS_enable_pgvector_extension.php`
- Create: `laravel/database/migrations/YYYY_MM_DD_HHMMSS_create_agent_documents_table.php`
- Create: `laravel/database/migrations/YYYY_MM_DD_HHMMSS_create_document_chunks_table.php`
- Modify: `laravel/config/database.php` if a pgsql custom Doctrine type needs registration for vector. (Likely not needed if migrations use `DB::statement`.)
- Test: `laravel/tests/Feature/Rag/DocumentSchemaTest.php`

- [ ] Verify pgvector is available on the target Postgres before running. If not, document the apt/docker step in `docs/operations/`.
- [ ] Enable extension:

```php
DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
```

- [ ] Create `agent_documents` migration with the columns above plus the check constraint and indexes.
- [ ] Create `document_chunks` migration using `DB::statement` to add the vector column:

```php
Schema::create('document_chunks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
    $table->foreignId('document_id')->constrained('agent_documents')->cascadeOnDelete();
    $table->integer('chunk_index');
    $table->text('content');
    $table->integer('tokens')->nullable();
    $table->json('metadata')->nullable();
    $table->timestamp('created_at')->useCurrent();
    $table->unique(['document_id', 'chunk_index']);
    $table->index(['workspace_id', 'document_id']);
});

DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(1536)');
```

- [ ] Feature test: migrate up/down, assert columns + extension installed.

Run:

```bash
cd /home/anderson/Oryntra/laravel
php artisan test --compact --filter=DocumentSchemaTest
```

Expected: PASS (or skipped with a clear notice if pgvector is absent on the dev DB).

## Task 2: Models, Factories, And Casts

**Files:**
- Create: `laravel/app/Models/AgentDocument.php`
- Create: `laravel/app/Models/DocumentChunk.php`
- Create: `laravel/database/factories/AgentDocumentFactory.php`
- Create: `laravel/database/factories/DocumentChunkFactory.php`
- Create: `laravel/app/Enums/AgentDocumentStatus.php`

- [ ] Enum:

```php
enum AgentDocumentStatus: string
{
    case Pending = 'pending';
    case Indexing = 'indexing';
    case Indexed = 'indexed';
    case Failed = 'failed';
}
```

- [ ] `AgentDocument` model with `belongsTo(Workspace)`, `hasMany(DocumentChunk)`, fillable, casts (`tags => array`, `metadata => array`, `index_status => AgentDocumentStatus`).
- [ ] `DocumentChunk` model. The `embedding` attribute requires a custom cast/accessor that converts the Postgres `vector` string `[1.2, 3.4, ...]` to `array<int, float>` and vice-versa. Implement an `Embedding` cast under `App\Casts\Embedding`.
- [ ] Factory states: `pending`, `indexed`, `failed`.

Run:

```bash
php artisan test --compact --filter='AgentDocumentFactory|DocumentChunkFactory'
```

Expected: PASS.

## Task 3: Storage And Upload Action

**Files:**
- Create: `laravel/app/Actions/Documents/StoreAgentDocument.php`
- Modify: `laravel/config/filesystems.php` (add `documents` disk if missing — local fallback in dev)
- Test: `laravel/tests/Feature/Rag/StoreAgentDocumentTest.php`

- [ ] Disk config (only if missing):

```php
'documents' => [
    'driver' => env('DOCUMENTS_DISK_DRIVER', 'local'),
    'root' => env('DOCUMENTS_DISK_ROOT', storage_path('app/documents')),
    'throw' => false,
],
```

- [ ] Action accepts `UploadedFile`, workspace id, optional tags. It:
  - Computes sha256 to deduplicate within a workspace.
  - Stores file at `workspaces/{workspace_id}/documents/{uuid}.{ext}`.
  - Inserts `agent_documents` row with `index_status = pending`.
  - Dispatches `IndexAgentDocumentJob` for that row.
- [ ] Test asserts: file persisted to disk, row created, job dispatched.

Run:

```bash
php artisan test --compact --filter=StoreAgentDocumentTest
```

Expected: PASS.

## Task 4: Text Extraction Service

**Files:**
- Create: `laravel/app/Services/Rag/DocumentTextExtractor.php`
- Test: `laravel/tests/Unit/Rag/DocumentTextExtractorTest.php`

- [ ] Supported mime types:
  - `text/plain`, `text/markdown` → direct read
  - `application/pdf` → `smalot/pdfparser`
  - `text/csv` → direct read
  - `application/vnd.openxmlformats-officedocument.wordprocessingml.document` (docx) → optional in this phase; if outside scope, return validation error from the upload action.
- [ ] If composer dependencies are not available, throw a typed `UnsupportedMimeTypeException`.
- [ ] Add `smalot/pdfparser` to composer require if not present. Confirm with the user before adding the dependency.
- [ ] Test against a fixture PDF and a fixture markdown file.

Run:

```bash
composer require smalot/pdfparser
php artisan test --compact --filter=DocumentTextExtractorTest
```

Expected: PASS.

## Task 5: Chunker

**Files:**
- Create: `laravel/app/Services/Rag/Chunker.php`
- Test: `laravel/tests/Unit/Rag/ChunkerTest.php`

- [ ] Sliding window chunker:
  - target_tokens default 500
  - overlap_tokens default 80
  - boundary preference: paragraph > sentence > word
  - returns `array<int, array{index:int,content:string,tokens:int}>`
- [ ] Token estimation: use a 4-chars-per-token heuristic. Avoid pulling tiktoken into PHP for this phase.
- [ ] Test edge cases: empty input, very long single line, mixed paragraphs, unicode.

Run:

```bash
php artisan test --compact --filter=ChunkerTest
```

Expected: PASS.

## Task 6: Embedding Provider

**Files:**
- Create: `laravel/app/Services/Rag/EmbeddingProvider.php` (interface)
- Create: `laravel/app/Services/Rag/OpenAiEmbeddingProvider.php`
- Create: `laravel/app/Services/Rag/NullEmbeddingProvider.php` (for tests / disabled state)
- Modify: `laravel/config/services.php` (`embeddings` section)
- Test: `laravel/tests/Feature/Rag/OpenAiEmbeddingProviderTest.php`

- [ ] Interface:

```php
interface EmbeddingProvider
{
    /**
     * @param  array<int, string> $inputs
     * @return array<int, array<int, float>>
     */
    public function embed(array $inputs): array;

    public function dimensions(): int;
}
```

- [ ] OpenAI implementation:
  - Uses `text-embedding-3-small` (1536 dim).
  - Reads API key from a workspace-level setting (preferred) or env fallback.
  - Batches inputs up to 100 per request.
  - Retries 3 times with backoff on 429/5xx.
- [ ] Null implementation returns deterministic stub vectors for tests.
- [ ] Test with `Http::fake()` covering happy path, retry, and 400 surface as exception.

Run:

```bash
php artisan test --compact --filter=OpenAiEmbeddingProviderTest
```

Expected: PASS.

## Task 7: Indexing Job

**Files:**
- Create: `laravel/app/Jobs/Rag/IndexAgentDocumentJob.php`
- Test: `laravel/tests/Feature/Rag/IndexAgentDocumentJobTest.php`

- [ ] Job pipeline:
  1. Load `AgentDocument`, set `index_status = indexing`.
  2. Extract text via `DocumentTextExtractor`.
  3. Chunk via `Chunker`.
  4. Embed in batches via `EmbeddingProvider`.
  5. Delete existing `document_chunks` for the document, then bulk insert new ones inside a transaction.
  6. Set `index_status = indexed`, store `chunks_count`, `indexed_at`.
- [ ] On exception:
  - Set `index_status = failed`, `index_error = $e->getMessage()`.
  - Allow Horizon retry (`$tries = 3`, exponential backoff).
- [ ] Use Horizon queue `rag` so RAG work does not starve agent-runtime queues.
- [ ] Test happy path and failure path with stubbed embedding provider.

Run:

```bash
php artisan test --compact --filter=IndexAgentDocumentJobTest
```

Expected: PASS.

## Task 8: Retrieval Service

**Files:**
- Create: `laravel/app/Services/Rag/KnowledgeBaseSearch.php`
- Test: `laravel/tests/Feature/Rag/KnowledgeBaseSearchTest.php`

- [ ] Input: workspace id, query string, optional tags filter, top_k, min_score.
- [ ] Algorithm:
  1. Embed the query via the provider.
  2. Run `SELECT id, document_id, content, metadata, 1 - (embedding <=> :query) AS score`.
  3. Filter `WHERE workspace_id = :workspace` and optionally `tags @> :tags::jsonb` joined via `agent_documents`.
  4. `ORDER BY embedding <=> :query` and `LIMIT :top_k`.
  5. Drop results where `score < min_score`.
- [ ] Output:

```php
/**
 * @return array<int, array{document_id:int,content:string,score:float,metadata:array<string,mixed>}>
 */
```

- [ ] Test asserts: tenant isolation, min_score filter, tags filter, top_k limit.

Run:

```bash
php artisan test --compact --filter=KnowledgeBaseSearchTest
```

Expected: PASS.

## Task 9: Native Tool Wiring

**Files:**
- Modify: `laravel/app/Services/AgentTools/NativeTool.php`
- Modify: `laravel/app/Services/AgentTools/NativeToolRegistry.php`
- Create: `laravel/app/Actions/AgentTools/SearchKnowledgeBase.php`
- Create: `laravel/app/Http/Controllers/Internal/SearchKnowledgeBaseController.php`
- Create: `laravel/app/Http/Requests/Internal/SearchKnowledgeBaseRequest.php`
- Modify: `laravel/routes/api.php`
- Test: `laravel/tests/Feature/Rag/SearchKnowledgeBaseControllerTest.php`

- [ ] Add `SearchKnowledgeBase = 'search_knowledge_base'` to the NativeTool enum and registry.
- [ ] Action validates payload, calls `KnowledgeBaseSearch`, returns serialized hits.
- [ ] Internal controller route: `POST /api/internal/agent-tools/search-knowledge-base` behind `internal.runtime` middleware.
- [ ] FormRequest:

```php
'workspace_id' => ['required', 'integer'],
'agent_id' => ['required', 'integer'],
'query' => ['required', 'string', 'max:1000'],
'top_k' => ['nullable', 'integer', 'min:1', 'max:20'],
'min_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
'tags' => ['nullable', 'array'],
'tags.*' => ['string', 'max:64'],
```

- [ ] Test asserts: 403 without token, 422 missing query, returns hits scoped to workspace.

Run:

```bash
php artisan test --compact --filter=SearchKnowledgeBaseControllerTest
```

Expected: PASS.

## Task 10: Filament Documents Resource

**Files:**
- Create: `laravel/app/Filament/Resources/AgentDocuments/AgentDocumentResource.php`
- Create: `laravel/app/Filament/Resources/AgentDocuments/Pages/ListAgentDocuments.php`
- Create: `laravel/app/Filament/Resources/AgentDocuments/Pages/CreateAgentDocument.php`
- Create: `laravel/app/Filament/Resources/AgentDocuments/Pages/ViewAgentDocument.php`
- Create: `laravel/app/Filament/Resources/AgentDocuments/Tables/AgentDocumentsTable.php`
- Create: `laravel/app/Filament/Resources/AgentDocuments/Schemas/AgentDocumentForm.php`
- Create: `laravel/app/Filament/Resources/AgentDocuments/Schemas/AgentDocumentInfolist.php`
- Test: `laravel/tests/Feature/Filament/AgentDocumentResourceTest.php`

- [ ] Navigation: group `Agentes`, label `Documentos`, icon `heroicon-o-document-text`.
- [ ] Form: FileUpload (PDF/MD/TXT), name, description, tags (TagsInput).
- [ ] Table columns: name, mime_type, size, status badge, chunks_count, indexed_at.
- [ ] Row actions: `View`, `Re-indexar` (dispatches the index job), `Delete`.
- [ ] Tenancy scoping mirrors existing resources.
- [ ] Infolist view page shows metadata + a paginated list of chunks (preview-only, no embeddings).
- [ ] Test asserts: upload happens, job dispatched, cross-tenant invisible.

Run:

```bash
php artisan test --compact --filter=AgentDocumentResourceTest
```

Expected: PASS.

## Task 11: Specialist UI — Knowledge Tag Filter

**Files:**
- Modify: `laravel/app/Filament/Resources/Agents/RelationManagers/SpecialistsRelationManager.php`
- Test: existing `AgentSupervisorAdminUxTest` extended.

- [ ] In the Especialista form add an optional TagsInput `rag_tags` under the Roteamento tab. Stored under `tools_config.search_knowledge_base.tags` jsonb. Empty = no tag filter.
- [ ] In `NativeToolRegistry`, when a specialist has `search_knowledge_base` allowlisted, surface its tags into the runtime payload sent to Python.

## Task 12: Python Contract

**Files:**
- Modify: `agent-python/src/oryntra_agent/api/schemas.py`
- Modify: `agent-python/src/oryntra_agent/agent/supervisor.py` (or wherever tool definitions live)
- Modify: `agent-python/tests/test_search_knowledge_base.py`

- [ ] Add `search_knowledge_base(query: str, top_k: int = 5, tags: list[str] | None = None)` as a Python tool that calls the Laravel internal endpoint.
- [ ] Tool returns concatenated `content` snippets plus structured citations.
- [ ] Add a test that proves the tool only calls Laravel and never talks to Postgres or MinIO directly.

Run:

```bash
cd /home/anderson/Oryntra/agent-python
uv run pytest tests/test_search_knowledge_base.py -q
uv run ruff check .
uv run mypy src/
```

Expected: PASS.

## Task 13: Quality Gates

**Files:** all modified files.

- [ ] `vendor/bin/pint --dirty --format agent`
- [ ] `php artisan test --compact`
- [ ] `vendor/bin/phpstan analyse --memory-limit=2G --no-progress`
- [ ] `uv run ruff check .`
- [ ] `uv run mypy src/`
- [ ] `uv run pytest`
- [ ] Manual smoke test:
  1. Upload a small PDF in the panel.
  2. Confirm `index_status` transitions to `indexed`.
  3. Run a test conversation referencing terms from the PDF and observe the specialist citing chunks (trace tab shows the `search_knowledge_base` tool call).

## Deferred

- Reranking with a cross-encoder model.
- Per-specialist allowed_tags enforcement at the SQL layer.
- Document-level ACLs (per user / per role).
- Periodic re-embedding when the provider model changes.
- IVFFlat index tuning (lists / probes) — created in a follow-up migration once row count justifies it.
- DOCX support beyond the optional path in Task 4.
- Audio/video document indexing.

## Self-Review

- Spec coverage: schema, ingestion, embeddings, retrieval, internal API, Filament UI, Python contract, quality gates.
- Placeholder scan: pgvector availability and Python tool wiring are explicit verification steps, not silent placeholders.
- Type consistency: `agent_documents`, `document_chunks`, `index_status`, `search_knowledge_base`, `rag_tags` are used consistently across migrations, models, controllers, and Python.
- Safety: tenancy enforced on every query; embeddings never leak across workspaces because `workspace_id` is part of the chunk row.
