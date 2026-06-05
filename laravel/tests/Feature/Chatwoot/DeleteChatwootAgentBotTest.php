<?php

declare(strict_types=1);

use App\Actions\Chatwoot\DeleteChatwootAgentBot;
use App\Jobs\Chatwoot\DeleteChatwootAgentBotJob;
use App\Models\ChatwootConnection;
use App\Models\ChatwootPlatformConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('dispatches a job to delete the Chatwoot agent bot when a connection is deleted', function () {
    Queue::fake();
    $connection = ChatwootConnection::factory()->create([
        'agent_bot_id' => 456,
    ]);

    $connection->delete();

    Queue::assertPushed(
        DeleteChatwootAgentBotJob::class,
        fn (DeleteChatwootAgentBotJob $job): bool => $job->agentBotId === 456
            && $job->queue === 'chatwoot-sync'
    );
});

it('does not dispatch a delete job when the connection has no agent bot', function () {
    Queue::fake();
    $connection = ChatwootConnection::factory()->create([
        'agent_bot_id' => null,
    ]);

    $connection->delete();

    Queue::assertNotPushed(DeleteChatwootAgentBotJob::class);
});

it('deletes the Chatwoot agent bot through the Platform API', function () {
    ChatwootPlatformConnection::create([
        'base_url' => 'https://chatwoot.test',
        'platform_token' => 'platform-token',
    ]);

    Http::fake([
        'https://chatwoot.test/platform/api/v1/agent_bots/456' => Http::response(['message' => 'ok']),
    ]);

    app(DeleteChatwootAgentBot::class)->execute(456);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://chatwoot.test/platform/api/v1/agent_bots/456'
        && $request->hasHeader('api_access_token', 'platform-token'));
});

it('treats a missing Chatwoot agent bot as already deleted', function () {
    ChatwootPlatformConnection::create([
        'base_url' => 'https://chatwoot.test',
        'platform_token' => 'platform-token',
    ]);

    Http::fake([
        'https://chatwoot.test/platform/api/v1/agent_bots/456' => Http::response(['message' => 'not found'], 404),
    ]);

    app(DeleteChatwootAgentBot::class)->execute(456);

    Http::assertSentCount(1);
});
