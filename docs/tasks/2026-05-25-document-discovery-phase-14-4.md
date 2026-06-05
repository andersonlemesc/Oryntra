# Phase 14.4 — Document Discovery, Sendability & Collision Fix

> **For agentic workers:** Use checkbox (`- [ ]`) syntax for tracking. Implement task-by-task. Run Pint + tests after each Laravel slice and pytest after each Python slice.

**Goal:** Make the `send_document` feature actually usable end-to-end by the AI. Phase 14.3 shipped the plumbing (tables, action, Chatwoot attachment, dispatch) but the AI can never call it correctly: it never learns valid document IDs, the two document tables collide on ID, standalone documents have no discovery tool, and there is no admin toggle to enable the tool. This phase closes those gaps and adds the knowledge-vs-sendable distinction by category.

---

## Findings that motivate this phase (verified in code)

1. **CRITICAL — AI never learns product document IDs.** `query_products` payload carries `documents` (with IDs), but `tool_runtime._make_query_products_tool.run()` formats only name/price/category/description into the text the LLM sees. The `documents` key is dropped → LLM has no ID to pass to `send_document`.
2. **CRITICAL — ID collision across tables.** `SendDocument::execute()` resolves `document_id` against `ProductDocument` first, then `Document`. Both tables have independent autoincrement IDs, so `ProductDocument id=5` and `Document id=5` coexist → wrong file sent silently.
3. **GAP — no discovery for standalone documents.** No `query_documents` tool exists. The `documents` table (faq/policy/catalog/manual) is unreachable by the AI.
4. **GAP — no Filament toggle for `send_document`.** Every other tool has a tab+toggle that auto-reconciles via `reconcileTool`. `send_document` can only be enabled by manually typing it into the raw `tools_allowlist` TagsInput.

## Decisions (locked with user)

- **Knowledge docs:** model the discriminator only this phase. Tools see sendable docs only. RAG/knowledge injection stays Phase 10. (No injection pipeline now.)
- **Discriminator lives on the category** (not per-document). A category is either sendable or knowledge-only.
- **`send_document` disambiguates with an explicit `document_type`** param: `'product' | 'standalone'`. Tables stay separate.
- **Per-specialist category filter** (Phase 14.4 round-1 decision): admin chooses which sendable categories this specialist may send.

## Out of scope

- Embeddings / semantic search / knowledge injection into prompts (Phase 10).
- Video sending, document preview, versioning, approval workflow.
- Making `product_documents` categorized — product attachments are always sendable.

---

## Design

### Category as purpose carrier — `DocumentCategory` enum

New `App\Enums\DocumentCategory` (string-backed) with `label(): string` and `isSendable(): bool`:

| case      | value       | label (pt)      | sendable |
|-----------|-------------|-----------------|----------|
| Catalog   | `catalog`   | Catálogo        | yes |
| Faq       | `faq`       | FAQ             | yes |
| Manual    | `manual`    | Manual          | yes |
| Policy    | `policy`    | Política        | yes |
| General   | `general`   | Geral           | yes |
| Knowledge | `knowledge` | Conhecimento IA | **no** |

`knowledge` is the bucket for "AI-only, never sent" docs. No new DB column — purpose is derived from `category` via the enum. `Document::scopeSendable()` filters `whereIn('category', DocumentCategory::sendableValues())`.

### `send_document` disambiguation

- Python `SendDocumentArgs` + `SendDocumentRequest`: add `document_type: Literal['product','standalone']`.
- `SendDocument` action branches on `document_type` (no more "try product then standalone"). For `standalone`, reject documents whose category `!isSendable()` with a `ValidationException`.

### `query_documents` tool (standalone discovery)

- New native tool `query_documents` → `QueryDocuments` action → `/api/internal/agent-tools/query-documents`.
- Searches `documents` workspace-scoped, **sendable categories only**, optionally narrowed by the calling specialist's `allowed_categories` (resolved server-side from `specialist_id`). Returns `[{id, title, category, original_filename}]`.
- Tool result text lists each doc so the LLM gets IDs → calls `send_document(document_id, document_type='standalone')`.

### `query_products` document rendering (fixes CRITICAL 1)

- `_make_query_products_tool.run()` appends a `Documentos:` line per product listing `[id] original_filename` so the LLM learns product doc IDs → `send_document(document_id, document_type='product')`.

### Filament — new "Documentos" tab on specialist

- `document_tools_config.query_enabled` toggle → reconciles `query_documents`.
- `document_tools_config.send_enabled` toggle → reconciles `send_document`.
- `document_tools_config.allowed_categories` multi-select (sendable categories only; visible when `send_enabled`). Empty = all sendable allowed.
- New migration `add_document_tools_config_to_agent_specialists_table`; add to `AgentSpecialist` `$fillable` + array cast.
- `normalizeSpecialistFormData()` reconciles both tools and filters `allowed_categories` to sendable values.
- `AgentRuntimeClient` passes `document_tools_config` in the specialist payload (so `QueryDocuments` can scope by `allowed_categories`).

---

## Tasks

### A. Category purpose
- [ ] Create `app/Enums/DocumentCategory.php` (cases, `label()`, `isSendable()`, `options()`, `sendableValues()`, `sendableOptions()`).
- [ ] `DocumentForm` + `DocumentsTable`: use enum `options()` for the category Select/Filter.
- [ ] `Document` model: add `scopeSendable(Builder $q)`; cast `category` to `DocumentCategory`.

### B. Collision fix — document_type
- [ ] Python `tools.py`: add `document_type` to `SendDocumentRequest`.
- [ ] Python `schemas.py`: add `document_type` to `SendDocumentArgs` (Literal, described).
- [ ] Python `tool_runtime._make_send_document_tool`: pass `document_type` through.
- [ ] Laravel `SendDocumentRequest`: validate `document_type` in `['product','standalone']`.
- [ ] Laravel `SendDocument` action: branch on `document_type`; for `standalone` reject non-sendable category.
- [ ] `DispatchAgentRunJob` send_document path: read + pass `document_type` from response payload.
- [ ] Python `RuntimeResponsePayload` (if it carries document_id): add `document_type`.

### C. query_products renders documents (CRITICAL 1)
- [ ] `_make_query_products_tool.run()`: append product documents (id + filename) to result text.
- [ ] Update `query_products` tool description to mention attached documents.

### D. query_documents tool (GAP 3)
- [ ] `NativeTool` enum: add `QueryDocuments = 'query_documents'`.
- [ ] `NativeToolRegistry`: add label/description.
- [ ] Laravel: `QueryDocuments` action, `QueryDocumentsController`, `QueryDocumentsRequest`, route + name.
- [ ] Server-side scope by sendable categories + specialist `allowed_categories`.
- [ ] Python `tools.py`: `QueryDocumentsRequest/Response` + `query_documents()`.
- [ ] Python `tool_runtime`: `QueryDocumentsArgs`, `_make_query_documents_tool`, register in `build_specialist_tools`, add to `EXECUTABLE_TOOLS`.

### E. Filament tab + reconcile (GAP 4)
- [ ] Migration `add_document_tools_config_to_agent_specialists_table` (jsonb, nullable).
- [ ] `AgentSpecialist`: `$fillable` + `casts` for `document_tools_config`.
- [ ] `SpecialistsRelationManager`: new "Documentos" tab (2 toggles + allowed_categories multi-select).
- [ ] `normalizeSpecialistFormData()`: reconcile `query_documents` + `send_document`, normalize `allowed_categories`.
- [ ] `AgentRuntimeClient`: include `document_tools_config` in specialist payload.

### F. Tests
- [ ] `SendDocumentToolTest`: document_type branching; same ID in both tables resolves correct table; standalone knowledge category rejected.
- [ ] `QueryDocuments` action/tool test (sendable-only, allowed_categories scoping).
- [ ] `NativeToolRegistryTest`: assert `query_documents` present.
- [ ] Filament specialist test: new toggles reconcile tools into `tools_allowlist`.
- [ ] Python `test_tool_runtime`: `query_documents` builder + `send_document` document_type arg.
- [ ] Update `query_products` test to assert documents rendered.

### G. Wrap-up
- [ ] `vendor/bin/pint --dirty --format agent`.
- [ ] `php artisan test --compact` (Laravel) + `pytest` (agent-python).
- [ ] Update `docs/tasks/ROADMAP.md` (Phase 14.4 row).

---

## Risk notes
- `send_document` downloads from S3 then uploads to Chatwoot synchronously inside a 10s httpx client timeout (Python side). Large files on slow links may time out on Python while Laravel still delivers → false error / possible double impression. Out of scope to fix here; flag for a follow-up (async delivery or longer timeout).
- `downloadToLocalTemp` uses the storage filename in the system temp dir without a per-run unique suffix; low collision risk, leave as-is.
