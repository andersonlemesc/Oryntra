<?php

declare(strict_types=1);

use App\Actions\AgentTools\CallGoogleCalendar;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\GoogleCalendarAuditLog;
use App\Models\GoogleCalendarConnection;
use App\Models\Workspace;
use App\Services\AgentTools\NativeTool;
use App\Services\AgentTools\NativeToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\postJson;

use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('services.agent_runtime.internal_token', 'ci-token');
    Config::set('services.google_calendar.client_id', 'test-client-id');
    Config::set('services.google_calendar.client_secret', 'test-client-secret');
    Config::set('services.google_calendar.redirect_uri', 'http://localhost/oauth/google-calendar/callback');
});

/**
 * @return array{workspace:Workspace, agent:Agent, specialist:AgentSpecialist, run:AgentRun, connection:GoogleCalendarConnection}
 */
function seedGcalScenario(array $overrides = []): array
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->active()->for($workspace)->create();
    $connection = GoogleCalendarConnection::factory()->for($workspace)->create();
    $specialist = AgentSpecialist::factory()->for($workspace)->for($agent)->create(array_merge([
        'tools_allowlist' => [NativeTool::GcalListEvents->value, NativeTool::GcalCreateEvent->value],
        'google_calendar_config' => [
            'enabled' => true,
            'connection_id' => $connection->id,
            'calendar_id' => 'primary',
            'notify_attendees_default' => true,
        ],
    ], $overrides));
    $run = AgentRun::factory()->create([
        'workspace_id' => $workspace->id,
        'agent_id' => $agent->id,
    ]);

    return compact('workspace', 'agent', 'specialist', 'run', 'connection');
}

it('rejects calls without the internal runtime token', function () {
    postJson('/api/internal/agent-tools/call-google-calendar', [])->assertForbidden();
});

it('rejects an unknown gcal tool name', function () {
    $scenario = seedGcalScenario();

    expect(fn () => app(CallGoogleCalendar::class)->execute([
        'workspace_id' => $scenario['workspace']->id,
        'agent_id' => $scenario['agent']->id,
        'agent_run_id' => $scenario['run']->id,
        'specialist_id' => $scenario['specialist']->id,
        'tool_name' => 'gcal_evil',
        'args' => [],
    ]))->toThrow(ValidationException::class);
});

it('rejects when the specialist allowlist excludes the tool', function () {
    $scenario = seedGcalScenario(['tools_allowlist' => []]);

    expect(fn () => app(CallGoogleCalendar::class)->execute([
        'workspace_id' => $scenario['workspace']->id,
        'agent_id' => $scenario['agent']->id,
        'agent_run_id' => $scenario['run']->id,
        'specialist_id' => $scenario['specialist']->id,
        'tool_name' => NativeTool::GcalListEvents->value,
        'args' => ['time_min' => now()->toIso8601String(), 'time_max' => now()->addDay()->toIso8601String()],
    ]))->toThrow(ValidationException::class);
});

it('rejects when google_calendar_config is disabled', function () {
    $scenario = seedGcalScenario([
        'google_calendar_config' => ['enabled' => false],
    ]);

    expect(fn () => app(CallGoogleCalendar::class)->execute([
        'workspace_id' => $scenario['workspace']->id,
        'agent_id' => $scenario['agent']->id,
        'agent_run_id' => $scenario['run']->id,
        'specialist_id' => $scenario['specialist']->id,
        'tool_name' => NativeTool::GcalListEvents->value,
        'args' => [],
    ]))->toThrow(ValidationException::class);
});

it('rejects when the connection belongs to another workspace (tenancy isolation)', function () {
    $otherWorkspace = Workspace::factory()->create();
    $intruderConnection = GoogleCalendarConnection::factory()->for($otherWorkspace)->create();

    $scenario = seedGcalScenario([
        'google_calendar_config' => [
            'enabled' => true,
            'connection_id' => $intruderConnection->id,
            'calendar_id' => 'primary',
            'notify_attendees_default' => true,
        ],
    ]);

    expect(fn () => app(CallGoogleCalendar::class)->execute([
        'workspace_id' => $scenario['workspace']->id,
        'agent_id' => $scenario['agent']->id,
        'agent_run_id' => $scenario['run']->id,
        'specialist_id' => $scenario['specialist']->id,
        'tool_name' => NativeTool::GcalListEvents->value,
        'args' => [],
    ]))->toThrow(ValidationException::class);
});

it('writes an audit log when the Google API call fails (e.g. invalid token)', function () {
    $scenario = seedGcalScenario();

    // Token de teste é inválido — Google API vai responder erro, mas action deve gravar audit log
    $result = app(CallGoogleCalendar::class)->execute([
        'workspace_id' => $scenario['workspace']->id,
        'agent_id' => $scenario['agent']->id,
        'agent_run_id' => $scenario['run']->id,
        'specialist_id' => $scenario['specialist']->id,
        'tool_name' => NativeTool::GcalListEvents->value,
        'args' => [
            'time_min' => now()->toIso8601String(),
            'time_max' => now()->addDay()->toIso8601String(),
        ],
    ]);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->not->toBeNull();

    $log = GoogleCalendarAuditLog::query()
        ->where('workspace_id', $scenario['workspace']->id)
        ->where('agent_run_id', $scenario['run']->id)
        ->first();

    expect($log)->not->toBeNull();
    expect($log->action)->toBe(NativeTool::GcalListEvents->value);
    expect($log->success)->toBeFalse();
    expect($log->error)->not->toBeNull();
});

it('registers all 5 gcal native tools in the registry', function () {
    $registry = app(NativeToolRegistry::class);
    $options = $registry->options();

    expect($options)->toHaveKey(NativeTool::GcalListEvents->value);
    expect($options)->toHaveKey(NativeTool::GcalCreateEvent->value);
    expect($options)->toHaveKey(NativeTool::GcalUpdateEvent->value);
    expect($options)->toHaveKey(NativeTool::GcalDeleteEvent->value);
    expect($options)->toHaveKey(NativeTool::GcalFindFreeSlots->value);
});
