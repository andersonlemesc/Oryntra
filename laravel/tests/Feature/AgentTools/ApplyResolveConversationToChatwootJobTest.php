<?php

declare(strict_types=1);

use App\Enums\AgentRunStatus;
use App\Jobs\Agent\ApplyResolveConversationToChatwootJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ChatwootConnection;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('sends customer_message, label and toggles status to resolved in order', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Completed,
        'output' => resolutionOutputForJobTest(),
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99' => Http::response(['status' => 'open']),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages' => Http::response(['id' => 123]),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels' => Http::response(['payload' => ['resolved-by-ai']]),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/toggle_status' => Http::response(['payload' => ['success' => true]]),
    ]);

    (new ApplyResolveConversationToChatwootJob($run->id))->handle();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages'
        && $request['content'] === 'Resolvido, ate breve!'
        && $request['private'] === false);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels'
        && $request['labels'] === ['resolved-by-ai']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/toggle_status'
        && ($request['status'] ?? null) === 'resolved');

    $output = $run->fresh()?->output;

    assert(is_array($output));

    expect($output['resolution']['side_effects']['status'])->toBe('completed')
        ->and($output['resolution']['side_effects']['actions']['customer_message'])->toBe('completed')
        ->and($output['resolution']['side_effects']['actions']['label'])->toBe('completed')
        ->and($output['resolution']['side_effects']['actions']['resolve'])->toBe('completed');
});

it('marks already_resolved when the conversation status is resolved before running', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Completed,
        'output' => resolutionOutputForJobTest(),
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99' => Http::response(['status' => 'resolved']),
    ]);

    (new ApplyResolveConversationToChatwootJob($run->id))->handle();

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/toggle_status');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages');

    $output = $run->fresh()?->output;

    assert(is_array($output));

    expect($output['resolution']['side_effects']['status'])->toBe('already_resolved')
        ->and($output['resolution']['side_effects']['actions']['resolve'])->toBe('skipped')
        ->and($output['resolution']['side_effects']['actions']['customer_message'])->toBe('skipped')
        ->and($output['resolution']['side_effects']['actions']['label'])->toBe('skipped');
});

it('skips customer_message and label when payload omits them', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
    ]);
    $output = resolutionOutputForJobTest();
    $output['resolution']['customer_message'] = null;
    $output['resolution']['label_name'] = null;
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Completed,
        'output' => $output,
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99' => Http::response(['status' => 'open']),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/toggle_status' => Http::response(['payload' => ['success' => true]]),
    ]);

    (new ApplyResolveConversationToChatwootJob($run->id))->handle();

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/toggle_status'
        && ($request['status'] ?? null) === 'resolved');

    $stored = $run->fresh()?->output;

    assert(is_array($stored));

    expect($stored['resolution']['side_effects']['status'])->toBe('completed')
        ->and($stored['resolution']['side_effects']['actions']['customer_message'])->toBe('skipped')
        ->and($stored['resolution']['side_effects']['actions']['label'])->toBe('skipped')
        ->and($stored['resolution']['side_effects']['actions']['resolve'])->toBe('completed');
});

it('continues to label and resolve when sending the customer_message fails', function () {
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = ChatwootConnection::factory()->for($workspace)->create([
        'base_url' => 'http://chatwoot.test',
        'account_id' => 5,
        'api_access_token' => 'agent-bot-token',
    ]);
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'chatwoot_connection_id' => $connection->id,
        'chatwoot_account_id' => 5,
        'conversation_id' => 99,
        'thread_id' => "workspace:{$workspace->id}:account:5:conversation:99",
        'status' => AgentRunStatus::Completed,
        'output' => resolutionOutputForJobTest(),
    ]);

    Http::fake([
        'http://chatwoot.test/api/v1/accounts/5/conversations/99' => Http::response(['status' => 'open']),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/messages' => Http::response(['error' => 'boom'], 500),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/labels' => Http::response(['payload' => ['resolved-by-ai']]),
        'http://chatwoot.test/api/v1/accounts/5/conversations/99/toggle_status' => Http::response(['payload' => ['success' => true]]),
    ]);

    (new ApplyResolveConversationToChatwootJob($run->id))->handle();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://chatwoot.test/api/v1/accounts/5/conversations/99/toggle_status'
        && ($request['status'] ?? null) === 'resolved');

    $stored = $run->fresh()?->output;

    assert(is_array($stored));

    expect($stored['resolution']['side_effects']['actions']['customer_message'])->toBe('failed')
        ->and($stored['resolution']['side_effects']['actions']['label'])->toBe('completed')
        ->and($stored['resolution']['side_effects']['actions']['resolve'])->toBe('completed')
        ->and($stored['resolution']['side_effects']['action_errors']['customer_message'])->toContain('HTTP 500');
});

/**
 * @return array<string, mixed>
 */
function resolutionOutputForJobTest(): array
{
    return [
        'resolution' => [
            'reason' => 'Cliente confirmou.',
            'resolution_summary' => 'Resolvido.',
            'customer_message' => 'Resolvido, ate breve!',
            'label_name' => 'resolved-by-ai',
            'requested_at' => now()->toISOString(),
            'side_effects' => [
                'status' => 'queued',
                'job_id' => null,
                'attempted_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'error' => null,
                'actions' => [
                    'customer_message' => 'pending',
                    'label' => 'pending',
                    'resolve' => 'pending',
                ],
            ],
        ],
        'trace' => [
            [
                'step' => 1,
                'type' => 'tool_call',
                'specialist_id' => 5,
                'tool' => 'resolve_conversation',
            ],
        ],
    ];
}
