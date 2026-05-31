<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ConnectorController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\KnowledgeDocumentController;
use App\Http\Controllers\Api\V1\LlmKeyController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\McpServerController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SpecialistController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\ChatwootWebhookController;
use App\Http\Controllers\Internal\AgentRunResumeController;
use App\Http\Controllers\Internal\CallExternalToolController;
use App\Http\Controllers\Internal\CallGoogleCalendarController;
use App\Http\Controllers\Internal\CallMcpToolController;
use App\Http\Controllers\Internal\GetChatwootContactController;
use App\Http\Controllers\Internal\QueryDocumentsController;
use App\Http\Controllers\Internal\QueryProductsController;
use App\Http\Controllers\Internal\RequestHumanHandoffController;
use App\Http\Controllers\Internal\RequestTeamHandoffController;
use App\Http\Controllers\Internal\ResolveConversationController;
use App\Http\Controllers\Internal\SearchKnowledgeBaseController;
use App\Http\Controllers\Internal\SendDocumentController;
use App\Http\Controllers\Internal\UpdateChatwootContactController;
use App\Http\Controllers\Internal\UpdateContactMemoryController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/chatwoot/{connectionUuid}', ChatwootWebhookController::class)
    ->middleware('chatwoot.webhook')
    ->name('chatwoot.webhooks.receive');

Route::middleware('internal.runtime')->group(function (): void {
    Route::post('internal/agent-tools/request-human-handoff', RequestHumanHandoffController::class)
        ->name('internal.agent-tools.request-human-handoff');

    Route::post('internal/agent-tools/request-team-handoff', RequestTeamHandoffController::class)
        ->name('internal.agent-tools.request-team-handoff');

    Route::post('internal/agent-tools/chatwoot-get-contact', GetChatwootContactController::class)
        ->name('internal.agent-tools.chatwoot-get-contact');

    Route::post('internal/agent-tools/chatwoot-update-contact', UpdateChatwootContactController::class)
        ->name('internal.agent-tools.chatwoot-update-contact');

    Route::post('internal/agent-tools/update-contact-memory', UpdateContactMemoryController::class)
        ->name('internal.agent-tools.update-contact-memory');

    Route::post('internal/agent-tools/resolve-conversation', ResolveConversationController::class)
        ->name('internal.agent-tools.resolve-conversation');

    Route::post('internal/agent-tools/query-products', QueryProductsController::class)
        ->name('internal.agent-tools.query-products');

    Route::post('internal/agent-tools/query-documents', QueryDocumentsController::class)
        ->name('internal.agent-tools.query-documents');

    Route::post('internal/agent-tools/search-knowledge-base', SearchKnowledgeBaseController::class)
        ->name('internal.agent-tools.search-knowledge-base');

    Route::post('internal/agent-tools/send-document', SendDocumentController::class)
        ->name('internal.agent-tools.send-document');

    Route::post('internal/agent-tools/call-external-tool', CallExternalToolController::class)
        ->name('internal.agent-tools.call-external-tool');

    Route::post('internal/agent-tools/call-google-calendar', CallGoogleCalendarController::class)
        ->name('internal.agent-tools.call-google-calendar');

    Route::post('internal/agent-tools/call-mcp-tool', CallMcpToolController::class)
        ->name('internal.agent-tools.call-mcp-tool');

    Route::post('internal/agent-runs/{agentRun}/resume', AgentRunResumeController::class)
        ->name('internal.agent-runs.resume');
});

/*
 * Public token-authenticated API consumed by the Oryntra MCP server. Every
 * token is scoped to a single workspace (api.workspace) and rate limited per
 * token (throttle:mcp). Abilities gate each resource via the `ability:` guard.
 */
Route::middleware(['auth:sanctum', 'throttle:mcp', 'api.workspace'])
    ->prefix('v1')
    ->as('api.v1.')
    ->group(function (): void {
        Route::get('me', MeController::class)->name('me');

        // Presigned upload (ability checked per purpose inside the controller).
        Route::post('uploads', [UploadController::class, 'store'])->name('uploads.store');

        // Agents
        Route::get('agents', [AgentController::class, 'index'])->middleware('ability:agent:read')->name('agents.index');
        Route::post('agents', [AgentController::class, 'store'])->middleware('ability:agent:write')->name('agents.store');
        Route::get('agents/{agent}', [AgentController::class, 'show'])->middleware('ability:agent:read')->name('agents.show');
        Route::match(['put', 'patch'], 'agents/{agent}', [AgentController::class, 'update'])->middleware('ability:agent:write')->name('agents.update');
        Route::delete('agents/{agent}', [AgentController::class, 'destroy'])->middleware('ability:agent:write')->name('agents.destroy');

        // Specialists (nested under agent for list/create; flat for item ops)
        Route::get('agents/{agent}/specialists', [SpecialistController::class, 'index'])->middleware('ability:specialist:read')->name('agents.specialists.index');
        Route::post('agents/{agent}/specialists', [SpecialistController::class, 'store'])->middleware('ability:specialist:write')->name('agents.specialists.store');
        Route::get('specialists/{specialist}', [SpecialistController::class, 'show'])->middleware('ability:specialist:read')->name('specialists.show');
        Route::match(['put', 'patch'], 'specialists/{specialist}', [SpecialistController::class, 'update'])->middleware('ability:specialist:write')->name('specialists.update');
        Route::delete('specialists/{specialist}', [SpecialistController::class, 'destroy'])->middleware('ability:specialist:write')->name('specialists.destroy');

        // Reference lookups for config-block id fields (handoff_config, google_calendar_config)
        Route::get('lookups/chatwoot/teams', [LookupController::class, 'chatwootTeams'])->middleware('ability:specialist:read')->name('lookups.chatwoot.teams');
        Route::get('lookups/chatwoot/agents', [LookupController::class, 'chatwootAgents'])->middleware('ability:specialist:read')->name('lookups.chatwoot.agents');
        Route::get('lookups/chatwoot/labels', [LookupController::class, 'chatwootLabels'])->middleware('ability:specialist:read')->name('lookups.chatwoot.labels');
        Route::get('lookups/calendar/connections', [LookupController::class, 'calendarConnections'])->middleware('ability:specialist:read')->name('lookups.calendar.connections');
        Route::get('lookups/calendar/connections/{connection}/calendars', [LookupController::class, 'calendarCalendars'])->middleware('ability:specialist:read')->name('lookups.calendar.calendars');

        // LLM keys (BYOK)
        Route::get('llm-keys', [LlmKeyController::class, 'index'])->middleware('ability:llmkey:read')->name('llm-keys.index');
        Route::post('llm-keys', [LlmKeyController::class, 'store'])->middleware('ability:llmkey:write')->name('llm-keys.store');
        Route::get('llm-keys/{llmKey}', [LlmKeyController::class, 'show'])->middleware('ability:llmkey:read')->name('llm-keys.show');
        Route::get('llm-keys/{llmKey}/models', [LlmKeyController::class, 'models'])->middleware('ability:llmkey:read')->name('llm-keys.models');
        Route::match(['put', 'patch'], 'llm-keys/{llmKey}', [LlmKeyController::class, 'update'])->middleware('ability:llmkey:write')->name('llm-keys.update');
        Route::delete('llm-keys/{llmKey}', [LlmKeyController::class, 'destroy'])->middleware('ability:llmkey:write')->name('llm-keys.destroy');

        // Categories
        Route::get('categories', [CategoryController::class, 'index'])->middleware('ability:category:read')->name('categories.index');
        Route::post('categories', [CategoryController::class, 'store'])->middleware('ability:category:write')->name('categories.store');
        Route::get('categories/{category}', [CategoryController::class, 'show'])->middleware('ability:category:read')->name('categories.show');
        Route::match(['put', 'patch'], 'categories/{category}', [CategoryController::class, 'update'])->middleware('ability:category:write')->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('ability:category:write')->name('categories.destroy');

        // Products
        Route::get('products', [ProductController::class, 'index'])->middleware('ability:product:read')->name('products.index');
        Route::post('products', [ProductController::class, 'store'])->middleware('ability:product:write')->name('products.store');
        Route::get('products/{product}', [ProductController::class, 'show'])->middleware('ability:product:read')->name('products.show');
        Route::match(['put', 'patch'], 'products/{product}', [ProductController::class, 'update'])->middleware('ability:product:write')->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('ability:product:write')->name('products.destroy');
        Route::get('products/{product}/documents', [ProductController::class, 'documents'])->middleware('ability:product:read')->name('products.documents.index');
        Route::post('products/{product}/documents', [ProductController::class, 'attachDocument'])->middleware('ability:media:write')->name('products.documents.store');
        Route::delete('products/{product}/documents/{document}', [ProductController::class, 'destroyDocument'])->middleware('ability:media:write')->name('products.documents.destroy');

        // Standalone documents (customer-sendable media)
        Route::get('documents', [DocumentController::class, 'index'])->middleware('ability:media:read')->name('documents.index');
        Route::post('documents', [DocumentController::class, 'store'])->middleware('ability:media:write')->name('documents.store');
        Route::get('documents/{document}', [DocumentController::class, 'show'])->middleware('ability:media:read')->name('documents.show');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->middleware('ability:media:write')->name('documents.destroy');

        // Knowledge base (RAG)
        Route::get('knowledge-documents', [KnowledgeDocumentController::class, 'index'])->middleware('ability:knowledge:read')->name('knowledge-documents.index');
        Route::post('knowledge-documents/from-text', [KnowledgeDocumentController::class, 'fromText'])->middleware('ability:knowledge:write')->name('knowledge-documents.from-text');
        Route::post('knowledge-documents/confirm', [KnowledgeDocumentController::class, 'confirmUpload'])->middleware('ability:knowledge:write')->name('knowledge-documents.confirm');
        Route::get('knowledge-documents/{knowledgeDocument}', [KnowledgeDocumentController::class, 'show'])->middleware('ability:knowledge:read')->name('knowledge-documents.show');
        Route::delete('knowledge-documents/{knowledgeDocument}', [KnowledgeDocumentController::class, 'destroy'])->middleware('ability:knowledge:write')->name('knowledge-documents.destroy');

        // External HTTP connectors
        Route::get('connectors', [ConnectorController::class, 'index'])->middleware('ability:tool:read')->name('connectors.index');
        Route::post('connectors', [ConnectorController::class, 'store'])->middleware('ability:tool:write')->name('connectors.store');
        Route::get('connectors/{connector}', [ConnectorController::class, 'show'])->middleware('ability:tool:read')->name('connectors.show');
        Route::match(['put', 'patch'], 'connectors/{connector}', [ConnectorController::class, 'update'])->middleware('ability:tool:write')->name('connectors.update');
        Route::delete('connectors/{connector}', [ConnectorController::class, 'destroy'])->middleware('ability:tool:write')->name('connectors.destroy');

        // MCP servers (consumed by the agent)
        Route::get('mcp-servers', [McpServerController::class, 'index'])->middleware('ability:tool:read')->name('mcp-servers.index');
        Route::post('mcp-servers', [McpServerController::class, 'store'])->middleware('ability:tool:write')->name('mcp-servers.store');
        Route::get('mcp-servers/{mcpServer}', [McpServerController::class, 'show'])->middleware('ability:tool:read')->name('mcp-servers.show');
        Route::get('mcp-servers/{mcpServer}/tools', [McpServerController::class, 'tools'])->middleware('ability:tool:read')->name('mcp-servers.tools');
        Route::match(['put', 'patch'], 'mcp-servers/{mcpServer}', [McpServerController::class, 'update'])->middleware('ability:tool:write')->name('mcp-servers.update');
        Route::delete('mcp-servers/{mcpServer}', [McpServerController::class, 'destroy'])->middleware('ability:tool:write')->name('mcp-servers.destroy');
    });
