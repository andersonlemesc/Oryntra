# Phase 14 — Send Document via MinIO (Product Documents + Standalone Library)

> **For agentic workers:** REQUIRED SUB-SKILL: Use checkbox (`- [ ]`) syntax for tracking. Implement task-by-task.

**Goal:** Allow Oryntra specialists to send PDF/image documents to customers via Chatwoot attachments. Admin uploads documents to Products (catalogs, spec sheets) or to a standalone Documents library. AI selects and sends via `send_document` tool.

**Architecture:** Two storage surfaces — (1) Product-attached documents (`product_documents` table) for catalogs/spec sheets that the IA discovers via `query_products`, and (2) a standalone `documents` table for general files (terms, policies, floor plans). Both store files in MinIO via Laravel's S3 disk. The `send_document` tool in Python calls a Laravel internal API endpoint that fetches the file from MinIO and sends it as a Chatwoot attachment via multipart upload.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5, Python 3.12, FastAPI, MinIO (S3), Chatwoot Messages API (multipart), Pest, pytest.

---

## Current State (verified)

- `RuntimeResponsePayload.type` already includes `"send_document"` and has a `document_id: int | None` field — unused scaffolding.
- `ChatwootAgentBotClient::sendConversationMessage()` only sends plain text — no attachment support.
- MinIO is configured as the default S3 disk (`FILESYSTEM_DISK=s3` in `.env`).
- Products table exists with `name, sku, description, price, category_id, metadata` — no document relation.
- `NativeTool` enum has no `SendDocument` case.
- Chatwoot Messages API supports `attachments[]` as multipart file upload — documented but not implemented.

## Out Of Scope

- Sending video files (use `send_document` for static files only).
- Document viewer/preview in the admin panel (Filament shows a download link).
- RAG/embeddings on document content (Phase 10).
- Versioning or approval workflow for documents.
- Automatic thumbnail generation.

## Design Decisions

### 1. Two tables: `product_documents` + `documents`

```
product_documents:
  id, workspace_id, product_id (FK), filename, original_filename,
  mime_type, size_bytes, path (S3 key), metadata (jsonb),
  created_at, updated_at

documents:
  id, workspace_id, category (varchar, e.g. "terms", "catalog", "policy"),
  title, description, filename, original_filename,
  mime_type, size_bytes, path (S3 key), metadata (jsonb),
  created_at, updated_at
```

Why both? Product-attached docs are discovered naturally when the IA queries a product (the payload includes `document_ids`). Standalone docs need a category/tag system so the IA can find them by intent. One tool `send_document(document_id, caption)` handles both — the AI just needs the ID.

### 2. File storage in MinIO

Files go to `s3://oryntra-storage/documents/{workspace_id}/{uuid}.{ext}`. Laravel's `Storage::disk('s3')` handles upload/download. No pre-signed URLs needed — Chatwoot API accepts multipart file upload directly.

### 3. Chatwoot attachment delivery

Chatwoot's Create Message API (`POST /api/v1/accounts/{id}/conversations/{id}/messages`) accepts:
- `content` (text body)
- `attachments[]` (multipart files)

We add `sendConversationMessageWithAttachment()` to `ChatwootAgentBotClient` that does a multipart POST.

### 4. Python tool flow

```
Specialist LLM → send_document(document_id=5, caption="Catálogo de apartamentos")
  → tool_runtime dispatches POST to Laravel /internal/agent-tools/send-document
  → Laravel resolves document → fetches from MinIO → sends to Chatwoot via multipart
  → Returns {sent: true, filename: "catalogo-apartamentos.pdf"}
  → Specialist response includes confirmation
```

### 5. Product payload enrichment

When `query_products` returns products, each product's `toAgentPayload()` includes a `documents` list with `{id, original_filename, mime_type}`. The IA sees docs available for each product and can call `send_document(id=X, caption="...")` to send them.

---

## File Structure

### Laravel (all under `laravel/`)

| File | Action | Responsibility |
|---|---|---|
| `database/migrations/2026_05_25_000000_create_product_documents_table.php` | CREATE | Product documents table |
| `database/migrations/2026_05_25_000001_create_documents_table.php` | CREATE | Standalone documents table |
| `app/Models/ProductDocument.php` | CREATE | Eloquent model + Storage URL accessors |
| `app/Models/Document.php` | CREATE | Eloquent model with category scope |
| `app/Models/Product.php` | MODIFY | Add `documents()` hasMany relation |
| `app/Filament/Resources/Products/Schemas/ProductForm.php` | MODIFY | Add FileUpload repeater for documents |
| `app/Filament/Resources/Documents/DocumentResource.php` | CREATE | Standalone document CRUD resource |
| `app/Services/AgentTools/NativeTool.php` | MODIFY | Add `SendDocument` case |
| `app/Services/AgentTools/NativeToolRegistry.php` | MODIFY | Add send_document label/description |
| `app/Services/AgentTools/NativeToolSendDocument.php` | CREATE | Action: resolve doc, send to Chatwoot |
| `app/Services/Chatwoot/ChatwootAgentBotClient.php` | MODIFY | Add `sendConversationMessageWithAttachment()` |
| `routes/internal.php` | MODIFY | Add POST `/agent-tools/send-document` |
| `app/Actions/AgentTools/SendDocument.php` | CREATE | Action: validate, dispatch job |

### Python (agent-python)

| File | Action | Responsibility |
|---|---|---|
| `src/oryntra_agent/agent/tool_runtime.py` | MODIFY | Add `send_document` to EXECUTABLE_TOOLS + builder |
| `src/oryntra_agent/agent/tools.py` | MODIFY | Add `send_document()` POST handler |
| `src/oryntra_agent/api/schemas.py` | MODIFY | Add `SendDocumentArgs` |

---

### Task 1: Migrations — product_documents + documents tables

**Files:**
- Create: `laravel/database/migrations/2026_05_25_000000_create_product_documents_table.php`
- Create: `laravel/database/migrations/2026_05_25_000001_create_documents_table.php`

- [ ] **Step 1: Create product_documents migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('filename')->unique();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('path');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_documents');
    }
};
```

- [ ] **Step 2: Create documents migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('category')->default('general');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('original_filename');
            $table->string('filename')->unique();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('path');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
```

- [ ] **Step 3: Run migration**

```bash
docker compose exec laravel-app php artisan migrate --force
```

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat: create product_documents and documents tables"
```

---

### Task 2: Models — ProductDocument + Document + Product relation

**Files:**
- Create: `laravel/app/Models/ProductDocument.php`
- Create: `laravel/app/Models/Document.php`
- Modify: `laravel/app/Models/Product.php`

- [ ] **Step 1: Create ProductDocument model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductDocument extends Model
{
    protected $fillable = [
        'workspace_id',
        'product_id',
        'original_filename',
        'filename',
        'mime_type',
        'size_bytes',
        'path',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function url(): string
    {
        return Storage::disk('s3')->url($this->path);
    }

    public function temporaryUrl(int $minutes = 30): string
    {
        return Storage::disk('s3')->temporaryUrl($this->path, now()->addMinutes($minutes));
    }

    public function toAgentPayload(): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
        ];
    }
}
```

- [ ] **Step 2: Create Document model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    protected $fillable = [
        'workspace_id',
        'category',
        'title',
        'description',
        'original_filename',
        'filename',
        'mime_type',
        'size_bytes',
        'path',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function url(): string
    {
        return Storage::disk('s3')->url($this->path);
    }

    public function temporaryUrl(int $minutes = 30): string
    {
        return Storage::disk('s3')->temporaryUrl($this->path, now()->addMinutes($minutes));
    }

    public function toAgentPayload(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
        ];
    }
}
```

- [ ] **Step 3: Add `documents()` relation to Product model**

Add after existing relations in `Product.php`:

```php
public function documents(): HasMany
{
    return $this->hasMany(ProductDocument::class);
}
```

Also add `ProductDocument` to the `use` imports and update `toAgentPayload()` to include documents:

```php
public function toAgentPayload(): array
{
    $payload = [
        'id' => $this->id,
        'name' => $this->name,
        'sku' => $this->sku,
        'description' => $this->description,
        'price' => $this->price,
        'category' => $this->category?->name,
    ];

    if ($this->relationLoaded('documents')) {
        $payload['documents'] = $this->documents->map->toAgentPayload()->all();
    }

    return $payload;
}
```

- [ ] **Step 4: Create factories**

```bash
docker compose exec laravel-app php artisan make:factory ProductDocumentFactory --no-interaction
docker compose exec laravel-app php artisan make:factory DocumentFactory --no-interaction
```

Fill in `database/factories/ProductDocumentFactory.php`:

```php
public function definition(): array
{
    return [
        'workspace_id' => Workspace::factory(),
        'product_id' => Product::factory(),
        'original_filename' => 'catalogo.pdf',
        'filename' => str()->uuid() . '.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 102400,
        'path' => 'documents/1/' . str()->uuid() . '.pdf',
        'metadata' => null,
    ];
}
```

Fill in `database/factories/DocumentFactory.php`:

```php
public function definition(): array
{
    return [
        'workspace_id' => Workspace::factory(),
        'category' => fake()->randomElement(['terms', 'catalog', 'policy', 'manual', 'general']),
        'title' => fake()->words(3, true),
        'description' => fake()->optional()->sentence(),
        'original_filename' => 'documento.pdf',
        'filename' => str()->uuid() . '.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 102400,
        'path' => 'documents/1/' . str()->uuid() . '.pdf',
        'metadata' => null,
    ];
}
```

- [ ] **Step 5: Pint**

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: ProductDocument + Document models, Product.documents relation"
```

---

### Task 3: ChatwootAgentBotClient — send message with attachment

**Files:**
- Modify: `laravel/app/Services/Chatwoot/ChatwootAgentBotClient.php`

- [ ] **Step 1: Add `sendConversationMessageWithAttachment()` method**

Add to the client class after the existing `sendConversationMessage()`:

```php
/**
 * Send a message with a file attachment to a Chatwoot conversation.
 *
 * @param  int     $conversationId  Chatwoot conversation ID
 * @param  string  $content          Text body (can be empty if attachment-only)
 * @param  string  $filePath         Absolute path to the temporary file
 * @param  string  $originalFilename Original filename for Content-Disposition
 * @param  string  $mimeType        MIME type (e.g. application/pdf)
 */
public function sendConversationMessageWithAttachment(
    int $conversationId,
    string $content,
    string $filePath,
    string $originalFilename,
    string $mimeType,
): array {
    return Http::withHeaders($this->chatwootHeaders())
        ->timeout(30)
        ->attach('attachments[]', file_get_contents($filePath), $originalFilename, ['Content-Type' => $mimeType])
        ->post("{$this->baseUrl}/api/v1/accounts/{$this->accountId}/conversations/{$conversationId}/messages", [
            'content' => $content,
            'message_type' => 'outgoing',
            'private' => false,
        ])
        ->json();
}
```

- [ ] **Step 2: Pint + commit**

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: ChatwootAgentBotClient send message with attachment"
```

---

### Task 4: NativeTool enum + SendDocument action + internal route

**Files:**
- Modify: `laravel/app/Services/AgentTools/NativeTool.php`
- Modify: `laravel/app/Services/AgentTools/NativeToolRegistry.php`
- Create: `laravel/app/Actions/AgentTools/SendDocument.php`
- Modify: `laravel/routes/internal.php`

- [ ] **Step 1: Add `SendDocument` to NativeTool enum**

```php
case SendDocument = 'send_document';
```

- [ ] **Step 2: Add to NativeToolRegistry**

```php
NativeTool::SendDocument->value => [
    'label' => 'Enviar documento',
    'description' => 'Envia um documento (PDF, imagem) ao cliente via Chatwoot.',
],
```

- [ ] **Step 3: Create SendDocument action**

```php
<?php

declare(strict_types=1);

namespace App\Actions\AgentTools;

use App\Models\Document;
use App\Models\ProductDocument;
use App\Models\Workspace;
use App\Services\Chatwoot\ChatwootAgentBotClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SendDocument
{
    /**
     * @return array{sent: bool, filename: string, error?: string}
     */
    public function execute(int $workspaceId, int $documentId, string $caption, int $conversationId, int $accountId): array
    {
        $productDoc = ProductDocument::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $documentId)
            ->first();

        $standaloneDoc = null;
        if ($productDoc === null) {
            $standaloneDoc = Document::query()
                ->where('workspace_id', $workspaceId)
                ->where('id', $documentId)
                ->first();
        }

        $doc = $productDoc ?? $standaloneDoc;

        if ($doc === null) {
            return ['sent' => false, 'filename' => '', 'error' => 'Document not found or not in workspace.'];
        }

        $s3Disk = Storage::disk('s3');

        if (! $s3Disk->exists($doc->path)) {
            return ['sent' => false, 'filename' => $doc->original_filename, 'error' => 'File not found in storage.'];
        }

        $localPath = $this->downloadToLocalTemp($doc->path, $doc->filename);

        try {
            $botClient = app(ChatwootAgentBotClient::class);
            $botClient->sendConversationMessageWithAttachment(
                conversationId: $conversationId,
                content: $caption,
                filePath: $localPath,
                originalFilename: $doc->original_filename,
                mimeType: $doc->mime_type,
            );

            return [
                'sent' => true,
                'filename' => $doc->original_filename,
            ];
        } catch (\Throwable $e) {
            Log::error('send_document.failed', [
                'workspace_id' => $workspaceId,
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'filename' => $doc->original_filename, 'error' => $e->getMessage()];
        } finally {
            if (file_exists($localPath)) {
                @unlink($localPath);
            }
        }
    }

    private function downloadToLocalTemp(string $s3Path, string $filename): string
    {
        $s3Disk = Storage::disk('s3');
        $stream = $s3Disk->readStream($s3Path);
        $localPath = sys_get_temp_dir() . '/' . $filename;

        file_put_contents($localPath, stream_get_contents($stream));
        fclose($stream);

        return $localPath;
    }
}
```

- [ ] **Step 4: Add internal route**

In `routes/internal.php`, add:

```php
Route::post('/agent-tools/send-document', [AgentToolsController::class, 'sendDocument']);
```

And in the controller, add the handler method. Read the existing controller to match patterns.

- [ ] **Step 5: Pint + tests**

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
docker compose exec laravel-app ./vendor/bin/pest tests/Feature/AgentTools/ --compact
```

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: SendDocument action + route + NativeTool entry"
```

---

### Task 5: Python — `send_document` tool

**Files:**
- Modify: `agent-python/src/oryntra_agent/agent/tool_runtime.py`
- Modify: `agent-python/src/oryntra_agent/agent/tools.py`
- Modify: `agent-python/src/oryntra_agent/api/schemas.py`

- [ ] **Step 1: Add `SendDocumentArgs` to schemas.py**

```python
class SendDocumentArgs(BaseModel):
    model_config = ConfigDict(extra="forbid")

    document_id: int = Field(description="ID do documento a enviar (PDF, imagem, etc).")
    caption: str = Field(default="", description="Texto descritivo que acompanha o documento.")
```

- [ ] **Step 2: Add `send_document` to EXECUTABLE_TOOLS and `_make_send_document_tool` in tool_runtime.py**

Add to `EXECUTABLE_TOOLS` frozenset:
```python
"send_document",
```

Add builder function:
```python
def _make_send_document_tool(ctx: ToolRuntimeContext) -> StructuredTool:
    from oryntra_agent.api.schemas import SendDocumentArgs

    def run(document_id: int, caption: str = "") -> str:
        from oryntra_agent.agent.tools import send_document

        result = send_document(
            workspace_id=ctx.workspace_id,
            document_id=document_id,
            caption=caption,
            conversation_id=ctx.conversation_id,
        )
        if result.get("sent"):
            return f"Documento '{result.get('filename', '')}' enviado com sucesso."
        return f"Falha ao enviar documento: {result.get('error', 'erro desconhecido')}"

    return StructuredTool.from_function(
        func=run,
        name="send_document",
        description="Envia um documento (PDF, imagem) previamente cadastrado ao cliente via Chatwoot. Use quando o cliente pede um catálogo, planta, ficha técnica, etc.",
        args_schema=SendDocumentArgs,
    )
```

Register in `build_specialist_tools`:
```python
if "send_document" in allowed_tools:
    tools.append(_make_send_document_tool(ctx))
```

- [ ] **Step 3: Add `send_document()` handler in tools.py**

```python
def send_document(workspace_id: int, document_id: int, caption: str, conversation_id: int) -> dict:
    base_url = settings.settings.agent_runtime_base_url
    token = settings.settings.internal_token
    response = httpx.post(
        f"{base_url}/internal/agent-tools/send-document",
        headers={"X-Internal-Token": token},
        json={
            "workspace_id": workspace_id,
            "document_id": document_id,
            "caption": caption,
            "conversation_id": conversation_id,
        },
        timeout=30,
    )
    response.raise_for_status()
    return response.json()
```

- [ ] **Step 4: Pint (Laravel) + ruff (Python) + tests**

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
cd agent-python && .venv/bin/ruff check src/
```

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: send_document Python tool + args schema"
```

---

### Task 6: Filament — product documents upload + standalone Document resource

**Files:**
- Modify: `laravel/app/Filament/Resources/Products/Schemas/ProductForm.php`
- Create: `laravel/app/Filament/Resources/Documents/DocumentResource.php`
- Create: `laravel/app/Filament/Resources/Documents/Pages/CreateDocument.php`
- Create: `laravel/app/Filament/Resources/Documents/Pages/EditDocument.php`
- Create: `laravel/app/Filament/Resources/Documents/Pages/ListDocuments.php`
- Create: `laravel/app/Filament/Resources/Documents/Schemas/DocumentForm.php`
- Create: `laravel/app/Filament/Resources/Documents/Tables/DocumentsTable.php`

- [ ] **Step 1: Add FileUpload repeater to ProductForm**

In `ProductForm.php`, add a "Documentos" section after the price/description fields:

```php
Section::make('Documentos')
    ->description('PDFs e imagens associados a este produto. O agente pode enviá-los ao cliente.')
    ->schema([
        Repeater::make('documents')
            ->relationship('documents')
            ->schema([
                FileUpload::make('path')
                    ->label('Arquivo')
                    ->disk('s3')
                    ->directory('documents')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(20480)
                    ->required(),
                TextInput::make('original_filename')
                    ->label('Nome do arquivo')
                    ->maxLength(255),
            ])
            ->collapsible()
            ->itemLabel(fn (array $state): string => $state['original_filename'] ?? 'Documento')
            ->addActionLabel('Adicionar documento'),
    ]),
```

- [ ] **Step 2: Create DocumentResource with Filament scaffolding**

```bash
docker compose exec laravel-app php artisan make:filament-resource Document --no-interaction
```

Then customize the form with FileUpload, category select, title, description.

- [ ] **Step 3: Pint + commit**

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: Product documents upload + Document Filament resource"
```

---

### Task 7: Enrich `query_products` payload with document IDs

**Files:**
- Modify: `laravel/app/Models/Product.php` — update `toAgentPayload()` to eager-load documents
- Modify: `laravel/app/Services/Products/ProductSearchService.php` — add `->with('documents')` to query

- [ ] **Step 1: Ensure `query_products` includes documents in each product payload**

In `ProductSearchService::search()`, add `->with('documents')` to the query.

In `Product::toAgentPayload()`, include documents when loaded:

```php
public function toAgentPayload(): array
{
    $payload = [
        'id' => $this->id,
        'name' => $this->name,
        'sku' => $this->sku,
        'description' => $this->description,
        'price' => $this->price,
        'category' => $this->category?->name,
    ];

    if ($this->relationLoaded('documents')) {
        $payload['documents'] = $this->documents->map->toAgentPayload()->all();
    }

    return $payload;
}
```

- [ ] **Step 2: Pint + tests**

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
docker compose exec laravel-app ./vendor/bin/pest tests/Feature/ --compact
```

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "feat: query_products payload includes product documents"
```

---

### Task 8: Laravel Pest tests

**Files:**
- Create: `laravel/tests/Feature/AgentTools/SendDocumentToolTest.php`
- Modify: `laravel/tests/Feature/AgentTools/NativeToolRegistryTest.php`

- [ ] **Step 1: Test NativeToolRegistry includes send_document**

Add to `NativeToolRegistryTest.php`:

```php
NativeTool::SendDocument->value => 'Enviar documento',
```

- [ ] **Step 2: Create SendDocumentToolTest**

Test that:
1. Document not in workspace → returns error
2. ProductDocument exists and file is in S3 → sends via Chatwoot client
3. Standalone Document works similarly

- [ ] **Step 3: Run tests**

```bash
docker compose exec laravel-app ./vendor/bin/pest tests/Feature/ --compact
```

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "test: SendDocument action and NativeToolRegistry assertions"
```

---

### Task 9: Dispatch response — handle `send_document` type

**Files:**
- Modify: `laravel/app/Jobs/DispatchAgentRunJob.php` (or equivalent response delivery)

- [ ] **Step 1: Add `send_document` branch in response delivery**

Find the response delivery code that currently handles `type === "text"` and `type === "clarify"`. Add a branch for `type === "send_document"`:

```php
if ($type === 'send_document') {
    $documentId = $responsePayload['document_id'] ?? null;
    $caption = $responseContent ?? '';

    if ($documentId !== null) {
        app(SendDocument::class)->execute(
            workspaceId: $run->workspace_id,
            documentId: (int) $documentId,
            caption: $caption,
            conversationId: $run->conversation_id,
            accountId: $run->chatwoot_account_id,
        );
    }

    continue;
}
```

- [ ] **Step 2: Pint + commit**

```bash
docker compose exec laravel-app ./vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: dispatch send_document response type"
```

---

### Task 10: Python tests for send_document tool

**Files:**
- Modify: `agent-python/tests/test_tool_runtime.py`

- [ ] **Step 1: Add test for send_document tool builder**

```python
def test_build_specialist_tools_builds_send_document_without_contact() -> None:
    tools = build_specialist_tools(
        ["send_document"],
        make_ctx(contact_id=None),
    )

    names = sorted(tool.name for tool in tools)
    assert names == ["send_document"]
```

- [ ] **Step 2: Run tests**

```bash
cd agent-python && .venv/bin/python -m pytest tests/test_tool_runtime.py -v --no-cov
```

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "test: send_document tool builder test"
```

---

### Task 11: Final verification + ROADMAP update

- [ ] **Step 1: Run full test suites**

```bash
docker compose exec laravel-app ./vendor/bin/pest tests/Feature/ --compact
cd agent-python && .venv/bin/python -m pytest tests/ -v --no-cov
```

- [ ] **Step 2: Run pint + ruff**

```bash
docker compose exec laravel-app ./vendor/bin/pint --format agent
cd agent-python && .venv/bin/ruff check src/
```

- [ ] **Step 3: Update ROADMAP**

Move Phase 14 from "Candidatas" to "Entregues".

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "docs: update ROADMAP — Phase 14 delivered"
```