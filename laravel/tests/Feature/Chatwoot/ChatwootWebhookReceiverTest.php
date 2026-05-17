<?php

use App\Jobs\Chatwoot\ProcessChatwootWebhookEventJob;
use App\Models\ChatwootConnection;
use App\Models\ChatwootWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

use function Pest\Laravel\postJson;

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

it('accepts unsigned webhooks for a provisioned Chatwoot agent bot connection', function () {
    Queue::fake();
    $connection = ChatwootConnection::factory()->create([
        'account_id' => 123,
        'agent_bot_id' => 456,
        'api_access_token' => 'agent-bot-token',
        'webhook_secret' => null,
    ]);
    $payload = chatwootWebhookPayload(messageId: 987, conversationId: 654, accountId: 123);

    postJson(chatwootWebhookUrl($connection), $payload)
        ->assertAccepted()
        ->assertJsonPath('status', 'queued');

    expect(ChatwootWebhookEvent::count())->toBe(1);
    Queue::assertPushed(ProcessChatwootWebhookEventJob::class, 1);
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

it('processes queued webhook events under a conversation lock', function () {
    $event = ChatwootWebhookEvent::factory()->create([
        'status' => 'queued',
        'conversation_id' => 654,
        'processed_at' => null,
    ]);

    (new ProcessChatwootWebhookEventJob($event->id))->handle();

    $event->refresh();

    expect($event->status)->toBe('processed')
        ->and($event->processing_started_at)->not->toBeNull()
        ->and($event->processed_at)->not->toBeNull();
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
 * @param  array<string, mixed>  $payload
 * @return array<string, string>
 */
function chatwootSignatureHeaders(array $payload, string $secret): array
{
    return [
        'X-Chatwoot-Signature' => hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret),
    ];
}
