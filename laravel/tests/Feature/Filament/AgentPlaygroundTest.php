<?php

declare(strict_types=1);

use App\Enums\AgentRunSource;
use App\Enums\PlaygroundMessageRole;
use App\Enums\PlaygroundMessageStatus;
use App\Filament\Pages\AgentPlayground;
use App\Jobs\Playground\StreamPlaygroundRunJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\PlaygroundConversation;
use App\Models\PlaygroundMessage;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function bootPlaygroundTenant(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->users()->attach($user, ['role' => 'owner']);

    actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($workspace);
    Filament::bootCurrentPanel();

    return [$user, $workspace];
}

it('creates conversation, messages and a playground run, and queues the stream job', function (): void {
    Queue::fake();
    [$user, $workspace] = bootPlaygroundTenant();
    $agent = Agent::factory()->active()->create(['workspace_id' => $workspace->id]);

    Livewire::test(AgentPlayground::class)
        ->set('agentId', $agent->id)
        ->set('draft', 'preciso de ajuda')
        ->call('sendMessage')
        ->assertHasNoErrors();

    $conversation = PlaygroundConversation::query()->firstOrFail();
    expect($conversation->user_id)->toBe($user->id);
    expect($conversation->thread_id)->toBe("workspace:{$workspace->id}:playground:{$conversation->id}");

    $user = PlaygroundMessage::query()->where('role', PlaygroundMessageRole::User)->firstOrFail();
    expect($user->content)->toBe('preciso de ajuda');

    $assistant = PlaygroundMessage::query()->where('role', PlaygroundMessageRole::Assistant)->firstOrFail();
    expect($assistant->status)->toBe(PlaygroundMessageStatus::Pending);
    expect($assistant->agent_run_id)->not->toBeNull();

    $run = AgentRun::query()->findOrFail($assistant->agent_run_id);
    expect($run->source)->toBe(AgentRunSource::Playground);
    expect($run->chatwoot_connection_id)->toBeNull();

    Queue::assertPushed(StreamPlaygroundRunJob::class, fn (StreamPlaygroundRunJob $job): bool => $job->playgroundMessageId === $assistant->id);
});

it('requires a message before sending', function (): void {
    [$user, $workspace] = bootPlaygroundTenant();
    Agent::factory()->active()->create(['workspace_id' => $workspace->id]);

    Livewire::test(AgentPlayground::class)
        ->set('draft', '   ')
        ->call('sendMessage')
        ->assertHasErrors('draft');

    expect(PlaygroundConversation::count())->toBe(0);
});

it('only lists conversations owned by the current user', function (): void {
    [$user, $workspace] = bootPlaygroundTenant();
    $agent = Agent::factory()->active()->create(['workspace_id' => $workspace->id]);

    $mine = PlaygroundConversation::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
        'user_id' => $user->id,
    ]);
    $theirs = PlaygroundConversation::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
    ]);

    $component = Livewire::test(AgentPlayground::class);
    $ids = $component->instance()->conversations()->pluck('id');

    expect($ids)->toContain($mine->id)->not->toContain($theirs->id);
});
