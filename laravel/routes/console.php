<?php

declare(strict_types=1);

use App\Jobs\Chatwoot\SyncChatwootAccountsJob;
use App\Jobs\Chatwoot\SyncChatwootContactsJob;
use App\Jobs\Chatwoot\SyncChatwootLabelsJob;
use App\Jobs\Chatwoot\SyncChatwootMetadataJob;
use App\Models\ChatwootConnection;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SyncChatwootAccountsJob)
    ->daily()
    ->name('chatwoot:sync-accounts-daily')
    ->onOneServer();

Schedule::call(function (): void {
    ChatwootConnection::query()
        ->whereNotNull('admin_api_token')
        ->whereNotNull('base_url')
        ->pluck('id')
        ->each(fn (int $connectionId) => SyncChatwootMetadataJob::dispatch($connectionId));
})
    ->daily()
    ->name('chatwoot:sync-metadata-daily')
    ->onOneServer();

Schedule::call(function (): void {
    ChatwootConnection::query()
        ->whereNotNull('admin_api_token')
        ->whereNotNull('base_url')
        ->pluck('id')
        ->each(fn (int $connectionId) => SyncChatwootContactsJob::dispatch($connectionId));
})
    ->hourly()
    ->name('chatwoot:sync-contacts-hourly')
    ->onOneServer();

Schedule::call(function (): void {
    ChatwootConnection::query()
        ->whereNotNull('admin_api_token')
        ->whereNotNull('base_url')
        ->pluck('id')
        ->each(fn (int $connectionId) => SyncChatwootLabelsJob::dispatch($connectionId));
})
    ->hourly()
    ->name('chatwoot:sync-labels-hourly')
    ->onOneServer();
