<?php

declare(strict_types=1);

use App\Http\Controllers\ChatwootWebhookController;
use App\Http\Controllers\Internal\AgentRunResumeController;
use App\Http\Controllers\Internal\RequestHumanHandoffController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/chatwoot/{connectionUuid}', ChatwootWebhookController::class)
    ->middleware('chatwoot.webhook')
    ->name('chatwoot.webhooks.receive');

Route::middleware('internal.runtime')->group(function (): void {
    Route::post('internal/agent-tools/request-human-handoff', RequestHumanHandoffController::class)
        ->name('internal.agent-tools.request-human-handoff');

    Route::post('internal/agent-runs/{agentRun}/resume', AgentRunResumeController::class)
        ->name('internal.agent-runs.resume');
});
