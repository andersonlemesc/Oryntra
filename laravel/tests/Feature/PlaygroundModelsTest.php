<?php

declare(strict_types=1);

use App\Enums\PlaygroundMessageRole;
use App\Enums\PlaygroundMessageStatus;
use App\Models\PlaygroundConversation;
use App\Models\PlaygroundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates a conversation with messages and casts jsonb columns', function (): void {
    $conversation = PlaygroundConversation::factory()->create();

    $user = PlaygroundMessage::factory()->create([
        'playground_conversation_id' => $conversation->id,
        'role' => PlaygroundMessageRole::User,
        'content' => 'oi',
    ]);

    $assistant = PlaygroundMessage::factory()->assistant()->create([
        'playground_conversation_id' => $conversation->id,
        'trace' => [['step' => 1, 'type' => 'runtime']],
        'usage' => ['total_cost_cents' => 3],
        'response' => ['type' => 'text', 'content' => 'ola'],
        'status' => PlaygroundMessageStatus::Completed,
    ]);

    expect($conversation->messages)->toHaveCount(2);
    expect($user->role)->toBe(PlaygroundMessageRole::User);
    expect($assistant->status)->toBe(PlaygroundMessageStatus::Completed);
    expect($assistant->trace)->toBeArray()->toHaveCount(1);
    expect($assistant->usage['total_cost_cents'])->toBe(3);
    expect($assistant->conversation->is($conversation))->toBeTrue();
});

it('builds a playground-scoped thread id', function (): void {
    $conversation = PlaygroundConversation::factory()->create();

    expect($conversation->buildThreadId())
        ->toBe("workspace:{$conversation->workspace_id}:playground:{$conversation->id}");
});
