<?php

use App\Http\Controllers\ChatwootWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/chatwoot/{connectionUuid}', ChatwootWebhookController::class)
    ->name('chatwoot.webhooks.receive');
