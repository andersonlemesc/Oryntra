<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\GoogleCalendarConnection;
use App\Services\GoogleCalendar\GoogleCalendarClient;
use App\Services\GoogleCalendar\GoogleCalendarConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only reference lookups used to fill the id fields of the agent/specialist
 * config blocks (Chatwoot teams/agents/labels for handoff_config, Google Calendar
 * connections/calendars for google_calendar_config). All workspace-scoped.
 */
class LookupController extends ApiController
{
    public function chatwootTeams(): JsonResponse
    {
        $teams = DB::table('chatwoot_teams')
            ->where('workspace_id', $this->workspaceId())
            ->orderBy('name')
            ->get(['chatwoot_team_id', 'name'])
            ->map(fn (object $team): array => [
                'team_id' => (int) $team->chatwoot_team_id,
                'name' => (string) $team->name,
            ]);

        return response()->json(['data' => $teams]);
    }

    public function chatwootAgents(Request $request): JsonResponse
    {
        $teamId = $request->integer('team_id');

        $query = DB::table('workspace_members')
            ->join('users', 'users.id', '=', 'workspace_members.user_id')
            ->where('workspace_members.workspace_id', $this->workspaceId())
            ->whereNotNull('workspace_members.chatwoot_user_id');

        if ($teamId > 0) {
            $query->whereIn(
                'workspace_members.chatwoot_user_id',
                DB::table('chatwoot_team_members')
                    ->where('workspace_id', $this->workspaceId())
                    ->where('chatwoot_team_id', $teamId)
                    ->pluck('chatwoot_user_id'),
            );
        }

        $agents = $query
            ->orderBy('users.name')
            ->get(['workspace_members.chatwoot_user_id', 'users.name'])
            ->map(fn (object $agent): array => [
                'agent_id' => (int) $agent->chatwoot_user_id,
                'name' => (string) $agent->name,
            ]);

        return response()->json(['data' => $agents]);
    }

    public function chatwootLabels(): JsonResponse
    {
        $labels = DB::table('chatwoot_labels')
            ->where('workspace_id', $this->workspaceId())
            ->orderBy('title')
            ->pluck('title')
            ->map(fn (mixed $title): array => ['title' => (string) $title]);

        return response()->json(['data' => $labels]);
    }

    public function calendarConnections(): JsonResponse
    {
        $connections = GoogleCalendarConnection::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('is_active', true)
            ->orderBy('label')
            ->get(['id', 'label', 'default_calendar_id'])
            ->map(fn (GoogleCalendarConnection $connection): array => [
                'connection_id' => $connection->getKey(),
                'label' => (string) $connection->label,
                'default_calendar_id' => $connection->default_calendar_id,
            ]);

        return response()->json(['data' => $connections]);
    }

    public function calendarCalendars(int $connection): JsonResponse
    {
        $model = $this->findInWorkspace(GoogleCalendarConnection::class, $connection);

        try {
            $calendars = (new GoogleCalendarClient($model, GoogleCalendarConfig::fromConfig()))->listCalendars();
        } catch (Throwable $exception) {
            return response()->json([
                'data' => [],
                'error' => 'Could not list calendars right now: ' . $exception->getMessage(),
            ]);
        }

        $data = array_map(fn (array $calendar): array => [
            'calendar_id' => $calendar['id'],
            'summary' => $calendar['summary'] ?? $calendar['id'],
            'primary' => (bool) ($calendar['primary'] ?? false),
        ], $calendars);

        return response()->json(['data' => $data]);
    }
}
