<?php

declare(strict_types=1);

use App\Actions\Chatwoot\ApplyConversationStateFromWebhook;
use App\Actions\Chatwoot\ClassifyChatwootWebhookEvent;
use App\Actions\Chatwoot\EnqueueAgentRunForEvent;
use App\Actions\Chatwoot\ResolveAgentForChatwootEvent;
use App\Enums\AgentChatwootBindingStatus;
use App\Jobs\Chatwoot\ProcessChatwootWebhookEventJob;
use App\Models\Agent;
use App\Models\AgentChatwootBinding;
use App\Models\ChatwootConnection;
use App\Models\ChatwootWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('receives a signed Chatwoot webhook, stores payload and dispatches processing job', function () {
    Queue::fake();
    $connection = ChatwootConnection::factory()->create([
        'account_id' => 123,
        'webhook_secret' => 'webhook-secret',
    ]);
    $payload = chatwootWebhookPayload(messageId: 987, conversationId: 654, accountId: 123);

    postJson(chatwootWebhookUrl($connection), $payload, chatwootSignatureHeaders($payload, 'webhook-secret'))
        ->assertAccepted()
        ->assertJsonPath('status', 'queued');

    $event = ChatwootWebhookEvent::query()->first();
    assert($event instanceof ChatwootWebhookEvent);

    expect($event->workspace_id)->toBe($connection->workspace_id)
        ->and($event->chatwoot_connection_id)->toBe($connection->id)
        ->and($event->event_name)->toBe('message_created')
        ->and($event->chatwoot_account_id)->toBe(123)
        ->and($event->conversation_id)->toBe(654)
        ->and($event->chatwoot_message_id)->toBe('987')
        ->and($event->payload['content'] ?? null)->toBe('Oi');

    Queue::assertPushed(
        ProcessChatwootWebhookEventJob::class,
        fn (ProcessChatwootWebhookEventJob $job): bool => $job->webhookEventId === $event->id
            && $job->queue === 'chatwoot-webhooks'
    );
});

it('rejects webhook requests with invalid signature', function () {
    Queue::fake();
    $connection = ChatwootConnection::factory()->create([
        'account_id' => 123,
        'webhook_secret' => 'webhook-secret',
    ]);
    $payload = chatwootWebhookPayload(accountId: 123);

    postJson(chatwootWebhookUrl($connection), $payload, [
        'X-Chatwoot-Signature' => 'invalid-signature',
    ])->assertUnauthorized();

    expect(ChatwootWebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('accepts Chatwoot signatures with sha256 prefix', function () {
    Queue::fake();
    $connection = ChatwootConnection::factory()->create([
        'account_id' => 123,
        'webhook_secret' => 'webhook-secret',
    ]);
    $payload = chatwootWebhookPayload(messageId: 987, conversationId: 654, accountId: 123);
    $signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), 'webhook-secret');

    postJson(chatwootWebhookUrl($connection), $payload, [
        'X-Chatwoot-Signature' => "sha256={$signature}",
    ])->assertAccepted();

    expect(ChatwootWebhookEvent::count())->toBe(1);
    Queue::assertPushed(ProcessChatwootWebhookEventJob::class, 1);
});

it('accepts agent bot signatures computed over timestamp and body', function () {
    Queue::fake();
    $connection = ChatwootConnection::factory()->create([
        'account_id' => 123,
        'webhook_secret' => 'webhook-secret',
    ]);
    $payload = chatwootWebhookPayload(messageId: 987, conversationId: 654, accountId: 123);
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", 'webhook-secret');

    postJson(chatwootWebhookUrl($connection), $payload, [
        'X-Chatwoot-Timestamp' => $timestamp,
        'X-Chatwoot-Signature' => "sha256={$signature}",
    ])->assertAccepted();

    expect(ChatwootWebhookEvent::count())->toBe(1);
    Queue::assertPushed(ProcessChatwootWebhookEventJob::class, 1);
});

it('rejects unsigned webhooks when no webhook secret is configured', function () {
    Queue::fake();
    $connection = ChatwootConnection::factory()->create([
        'account_id' => 123,
        'agent_bot_id' => 456,
        'api_access_token' => 'agent-bot-token',
        'webhook_secret' => null,
    ]);
    $payload = chatwootWebhookPayload(messageId: 987, conversationId: 654, accountId: 123);

    postJson(chatwootWebhookUrl($connection), $payload)
        ->assertUnauthorized();

    expect(ChatwootWebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects webhook requests for a different Chatwoot account', function () {
    Queue::fake();
    $connection = ChatwootConnection::factory()->create([
        'account_id' => 123,
        'webhook_secret' => 'webhook-secret',
    ]);
    $payload = chatwootWebhookPayload(accountId: 456);

    postJson(chatwootWebhookUrl($connection), $payload, chatwootSignatureHeaders($payload, 'webhook-secret'))
        ->assertUnprocessable();

    expect(ChatwootWebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('is idempotent by chatwoot message id', function () {
    Queue::fake();
    $connection = ChatwootConnection::factory()->create([
        'account_id' => 123,
        'webhook_secret' => 'webhook-secret',
    ]);
    $payload = chatwootWebhookPayload(messageId: 987, accountId: 123);
    $headers = chatwootSignatureHeaders($payload, 'webhook-secret');

    postJson(chatwootWebhookUrl($connection), $payload, $headers)
        ->assertAccepted();
    postJson(chatwootWebhookUrl($connection), $payload, $headers)
        ->assertOk()
        ->assertJsonPath('status', 'duplicate');

    expect(ChatwootWebhookEvent::count())->toBe(1);
    Queue::assertPushed(ProcessChatwootWebhookEventJob::class, 1);
});

it('processes queued webhook events under a conversation lock and enters debounce', function () {
    Bus::fake();

    $event = ChatwootWebhookEvent::factory()->create([
        'status' => 'queued',
        'conversation_id' => 654,
        'processed_at' => null,
    ]);

    $agent = Agent::factory()->active()->create([
        'workspace_id' => $event->workspace_id,
    ]);
    AgentChatwootBinding::factory()->create([
        'workspace_id' => $event->workspace_id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $event->chatwoot_connection_id,
        'status' => AgentChatwootBindingStatus::Active,
    ]);

    (new ProcessChatwootWebhookEventJob($event->id))->handle(
        app(ClassifyChatwootWebhookEvent::class),
        app(ApplyConversationStateFromWebhook::class),
        app(ResolveAgentForChatwootEvent::class),
        app(EnqueueAgentRunForEvent::class),
    );

    $event->refresh();

    expect($event->status)->toBe('debouncing')
        ->and($event->resolved_agent_id)->toBe($agent->id)
        ->and($event->agent_run_id)->not->toBeNull()
        ->and($event->processing_started_at)->not->toBeNull();
});

it('ignores the bot own outgoing Chatwoot messages so Oryntra does not reply to itself', function () {
    $event = ChatwootWebhookEvent::factory()->create([
        'event_name' => 'message_created',
        'conversation_id' => 654,
        'chatwoot_message_id' => '987',
        'status' => 'queued',
        'payload' => chatwootRealMessagePayload(messageType: 'outgoing', senderType: 'agent_bot'),
    ]);

    (new ProcessChatwootWebhookEventJob($event->id))->handle(
        app(ClassifyChatwootWebhookEvent::class),
        app(ApplyConversationStateFromWebhook::class),
        app(ResolveAgentForChatwootEvent::class),
        app(EnqueueAgentRunForEvent::class),
    );

    $event->refresh();

    expect($event->status)->toBe('ignored')
        ->and($event->ignored_reason)->toBe('not_incoming_message')
        ->and($event->processed_at)->not->toBeNull();
});

it('ignores private Chatwoot messages', function () {
    $event = ChatwootWebhookEvent::factory()->create([
        'event_name' => 'message_created',
        'conversation_id' => 654,
        'chatwoot_message_id' => '987',
        'status' => 'queued',
        'payload' => chatwootRealMessagePayload(private: true),
    ]);

    (new ProcessChatwootWebhookEventJob($event->id))->handle(
        app(ClassifyChatwootWebhookEvent::class),
        app(ApplyConversationStateFromWebhook::class),
        app(ResolveAgentForChatwootEvent::class),
        app(EnqueueAgentRunForEvent::class),
    );

    $event->refresh();

    expect($event->status)->toBe('ignored')
        ->and($event->ignored_reason)->toBe('private_message');
});

it('processes incoming Chatwoot media messages with captions', function () {
    Bus::fake();

    $event = ChatwootWebhookEvent::factory()->create([
        'event_name' => 'message_created',
        'conversation_id' => 654,
        'chatwoot_message_id' => '987',
        'status' => 'queued',
        'payload' => chatwootRealMessagePayload(
            content: 'Imagem com legenda',
            attachments: [[
                'id' => 6,
                'file_type' => 'image',
                'content_type' => 'image/jpeg',
                'data_url' => 'http://localhost:3000/rails/active_storage/blobs/redirect/example',
                'thumb_url' => 'http://localhost:3000/rails/active_storage/representations/redirect/example',
            ]],
        ),
    ]);

    $agent = Agent::factory()->active()->create([
        'workspace_id' => $event->workspace_id,
    ]);
    AgentChatwootBinding::factory()->create([
        'workspace_id' => $event->workspace_id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $event->chatwoot_connection_id,
        'status' => AgentChatwootBindingStatus::Active,
    ]);

    $classification = app(ClassifyChatwootWebhookEvent::class)->execute($event);
    (new ProcessChatwootWebhookEventJob($event->id))->handle(
        app(ClassifyChatwootWebhookEvent::class),
        app(ApplyConversationStateFromWebhook::class),
        app(ResolveAgentForChatwootEvent::class),
        app(EnqueueAgentRunForEvent::class),
    );

    $event->refresh();

    expect($classification['should_process'])->toBeTrue()
        ->and($classification['normalized']['content'])->toBe('Imagem com legenda')
        ->and($classification['normalized']['attachments'])->toHaveCount(1)
        ->and($classification['normalized']['attachments'][0]['file_type'])->toBe('image')
        ->and($event->status)->toBe('debouncing')
        ->and($event->resolved_agent_id)->toBe($agent->id)
        ->and($event->agent_run_id)->not->toBeNull()
        ->and($event->ignored_reason)->toBeNull();
});

function chatwootWebhookUrl(ChatwootConnection $connection): string
{
    return route('chatwoot.webhooks.receive', ['connectionUuid' => $connection->connection_uuid]);
}

/**
 * @return array<string, mixed>
 */
function chatwootWebhookPayload(int $messageId = 987, int $conversationId = 654, int $accountId = 123): array
{
    return [
        'event' => 'message_created',
        'id' => $messageId,
        'content' => 'Oi',
        'account' => [
            'id' => $accountId,
            'name' => 'Empresa A',
        ],
        'conversation' => [
            'id' => $conversationId,
        ],
        'message_type' => 0,
    ];
}

/**
 * @param  array<int, array<string, mixed>> $attachments
 * @return array<string, mixed>
 */
function chatwootRealMessagePayload(
    string $messageType = 'incoming',
    string $senderType = 'Contact',
    bool $private = false,
    ?string $content = 'Oi',
    array $attachments = [],
): array {
    return [
        'id' => 987,
        'event' => 'message_created',
        'inbox' => [
            'id' => 1,
            'name' => 'Oryndra',
        ],
        'sender' => [
            'id' => 4,
            'name' => 'Iaah02',
            'type' => $senderType,
        ],
        'account' => [
            'id' => 123,
            'name' => 'Empresa A',
        ],
        'content' => $content,
        'private' => $private,
        'source_id' => null,
        'created_at' => '2026-05-17T17:55:21.000Z',
        'attachments' => $attachments,
        'content_type' => 'text',
        'conversation' => [
            'id' => 654,
            'inbox_id' => 1,
        ],
        'message_type' => $messageType,
        'content_attributes' => [],
        'additional_attributes' => [],
    ];
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, string>
 */
function chatwootSignatureHeaders(array $payload, string $secret): array
{
    return [
        'X-Chatwoot-Signature' => hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret),
    ];
}
