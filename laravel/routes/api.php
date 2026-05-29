<?php

declare(strict_types=1);

use App\Http\Controllers\ChatwootWebhookController;
use App\Http\Controllers\Internal\AgentRunResumeController;
use App\Http\Controllers\Internal\CallExternalToolController;
use App\Http\Controllers\Internal\CallGoogleCalendarController;
use App\Http\Controllers\Internal\GetChatwootContactController;
use App\Http\Controllers\Internal\QueryDocumentsController;
use App\Http\Controllers\Internal\QueryProductsController;
use App\Http\Controllers\Internal\RequestHumanHandoffController;
use App\Http\Controllers\Internal\RequestTeamHandoffController;
use App\Http\Controllers\Internal\ResolveConversationController;
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

    Route::post('internal/agent-tools/send-document', SendDocumentController::class)
        ->name('internal.agent-tools.send-document');

    Route::post('internal/agent-tools/call-external-tool', CallExternalToolController::class)
        ->name('internal.agent-tools.call-external-tool');

    Route::post('internal/agent-tools/call-google-calendar', CallGoogleCalendarController::class)
        ->name('internal.agent-tools.call-google-calendar');

    Route::post('internal/agent-runs/{agentRun}/resume', AgentRunResumeController::class)
        ->name('internal.agent-runs.resume');
});
