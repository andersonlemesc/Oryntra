<?php

declare(strict_types=1);

use App\Actions\Chatwoot\EnqueueAgentRunForEvent;
use App\Models\Agent;
use App\Models\ChatwootConnection;
use App\Models\ChatwootWebhookEvent;
use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('creates a contact from the webhook sender block and links it to the agent_run', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();

    $event = ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 77,
        'chatwoot_account_id' => 5,
        'payload' => [
            'event' => 'message_created',
            'message_type' => 'incoming',
            'conversation' => [
                'meta' => [
                    'sender' => [
                        'id' => 42,
                        'name' => 'Anderson Lemes',
                        'email' => 'anderson@example.com',
                        'phone_number' => '+5511999990000',
                        'identifier' => null,
                        'thumbnail' => null,
                        'additional_attributes' => [],
                        'custom_attributes' => ['origem' => 'whatsapp'],
                    ],
                ],
            ],
        ],
    ]);

    $run = app(EnqueueAgentRunForEvent::class)->execute($event, $agent, [
        'content' => 'oi',
        'attachments' => [],
    ]);

    $contact = Contact::query()->where('chatwoot_contact_id', 42)->first();

    expect($contact)->not->toBeNull()
        ->and($contact?->name)->toBe('Anderson Lemes')
        ->and($contact?->email)->toBe('anderson@example.com')
        ->and($contact?->chatwoot_custom_attributes)->toBe(['origem' => 'whatsapp'])
        ->and($run->contact_id)->toBe($contact?->id);
});

it('reuses the same contact when subsequent webhooks land for the same conversation', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create([
        'debounce_config' => ['window_seconds' => 60],
    ]);

    $payload = [
        'event' => 'message_created',
        'conversation' => [
            'meta' => [
                'sender' => [
                    'id' => 42,
                    'name' => 'Anderson',
                ],
            ],
        ],
    ];

    $eventA = ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 77,
        'chatwoot_account_id' => 5,
        'payload' => $payload,
    ]);
    $eventB = ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 77,
        'chatwoot_account_id' => 5,
        'payload' => $payload,
    ]);

    app(EnqueueAgentRunForEvent::class)->execute($eventA, $agent, ['content' => 'a', 'attachments' => []]);
    app(EnqueueAgentRunForEvent::class)->execute($eventB, $agent, ['content' => 'b', 'attachments' => []]);

    expect(Contact::query()->count())->toBe(1);
});

it('handles webhooks without a sender block without throwing', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create();
    $agent = Agent::factory()->active()->for($workspace)->create();

    $event = ChatwootWebhookEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'chatwoot_connection_id' => $connection->id,
        'conversation_id' => 77,
        'chatwoot_account_id' => 5,
        'payload' => ['event' => 'message_created'],
    ]);

    $run = app(EnqueueAgentRunForEvent::class)->execute($event, $agent, [
        'content' => 'sem sender',
        'attachments' => [],
    ]);

    expect(Contact::query()->count())->toBe(0)
        ->and($run->contact_id)->toBeNull();
});
