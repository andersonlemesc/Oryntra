<?php

declare(strict_types=1);

use App\Http\Controllers\ChatwootWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/chatwoot/{connectionUuid}', ChatwootWebhookController::class)
    ->middleware('chatwoot.webhook')
    ->name('chatwoot.webhooks.receive');
