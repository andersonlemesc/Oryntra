<?php

declare(strict_types=1);

use App\Models\ChatwootConnection;
use App\Models\Workspace;
use App\Services\Chatwoot\ChatwootAgentBotClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('sends public messages through the Chatwoot agent bot', function () {
    $client = chatwootAgentBotClientForTest();

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages' => Http::response(['id' => 123]),
    ]);

    $client->sendConversationMessage(99, 'Mensagem publica');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages'
        && $request->hasHeader('api_access_token', 'agent-bot-token')
        && $request['content'] === 'Mensagem publica'
        && $request['message_type'] === 'outgoing'
        && $request['private'] === false);
});

it('adds private notes through the Chatwoot agent bot', function () {
    $client = chatwootAgentBotClientForTest();

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages' => Http::response(['id' => 124]),
    ]);

    $client->addPrivateNote(99, 'Nota interna');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages'
        && $request->hasHeader('api_access_token', 'agent-bot-token')
        && $request['content'] === 'Nota interna'
        && $request['message_type'] === 'outgoing'
        && $request['private'] === true);
});

it('adds labels through the Chatwoot agent bot', function () {
    $client = chatwootAgentBotClientForTest();

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels' => Http::response(['payload' => ['human_handoff']]),
    ]);

    $client->addConversationLabel(99, 'human_handoff');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels'
        && $request->hasHeader('api_access_token', 'agent-bot-token')
        && $request['labels'] === ['human_handoff']);
});

it('assigns teams through the Chatwoot agent bot', function () {
    $client = chatwootAgentBotClientForTest();

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/assignments' => Http::response(['id' => 99]),
    ]);

    $client->assignTeam(99, 12);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/assignments'
        && $request->hasHeader('api_access_token', 'agent-bot-token')
        && $request['team_id'] === 12);
});

it('assigns agents through the Chatwoot agent bot', function () {
    $client = chatwootAgentBotClientForTest();

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/assignments' => Http::response(['id' => 99]),
    ]);

    $client->assignAgent(99, 34);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/assignments'
        && $request->hasHeader('api_access_token', 'agent-bot-token')
        && $request['assignee_id'] === 34);
});

function chatwootAgentBotClientForTest(): ChatwootAgentBotClient
{
    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
    ]);

    return new ChatwootAgentBotClient($connection);
}
