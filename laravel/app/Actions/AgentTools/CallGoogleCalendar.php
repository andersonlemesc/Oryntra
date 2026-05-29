<?php

declare(strict_types=1);

namespace App\Actions\AgentTools;

use App\Models\AgentRun;
use App\Models\AgentSpecialist;
use App\Models\GoogleCalendarAuditLog;
use App\Models\GoogleCalendarConnection;
use App\Services\AgentTools\NativeTool;
use App\Services\GoogleCalendar\Exceptions\GoogleCalendarException;
use App\Services\GoogleCalendar\GoogleCalendarClient;
use App\Services\GoogleCalendar\GoogleCalendarConfig;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

class CallGoogleCalendar
{
    private const ALLOWED_TOOLS = [
        NativeTool::GcalListEvents->value,
        NativeTool::GcalCreateEvent->value,
        NativeTool::GcalUpdateEvent->value,
        NativeTool::GcalDeleteEvent->value,
        NativeTool::GcalFindFreeSlots->value,
    ];

    /**
     * @param  array{workspace_id:int,agent_id:int,agent_run_id:int,specialist_id:int,tool_name:string,args?:array<string,mixed>} $payload
     * @return array{success:bool,result:string,error:?string,http_status:?int}
     */
    public function execute(array $payload): array
    {
        $toolName = (string) $payload['tool_name'];
        $this->assertToolKnown($toolName);

        $run = $this->loadRun($payload);
        $specialist = $this->loadSpecialist($payload);
        $this->assertToolAllowed($specialist, $toolName);

        $config = is_array($specialist->google_calendar_config) ? $specialist->google_calendar_config : [];

        if (! ($config['enabled'] ?? false)) {
            throw ValidationException::withMessages([
                'tool_name' => 'Google Calendar não está habilitado neste especialista.',
            ]);
        }

        $connectionId = isset($config['connection_id']) ? (int) $config['connection_id'] : 0;
        $calendarId = (string) ($config['calendar_id'] ?? 'primary');
        $notifyDefault = (bool) ($config['notify_attendees_default'] ?? true);

        $connection = $this->loadConnection($payload['workspace_id'], $connectionId);

        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        $startedAt = microtime(true);
        $eventIdForLog = null;
        $result = null;
        $error = null;
        $success = false;

        try {
            $client = new GoogleCalendarClient($connection, GoogleCalendarConfig::fromConfig());
            $result = $this->dispatch($client, $toolName, $calendarId, $args, $notifyDefault);
            $eventIdForLog = $this->extractEventId($result);
            $success = true;
        } catch (GoogleCalendarException|Throwable $e) {
            $error = $e->getMessage();
            $result = ['error' => $error];
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->writeAudit(
            workspaceId: (int) $payload['workspace_id'],
            connectionId: $connection->id,
            agentRunId: $run->id,
            specialistId: $specialist->id,
            toolName: $toolName,
            calendarId: $calendarId,
            eventId: $eventIdForLog,
            args: $args,
            success: $success,
            latencyMs: $latencyMs,
            result: $result,
            error: $error,
        );

        if ($success) {
            $connection->forceFill(['last_used_at' => now()])->save();
        }

        return [
            'success' => $success,
            'result' => $this->encodeResult($result),
            'error' => $error,
            'http_status' => $success ? 200 : null,
        ];
    }

    /**
     * @param  array<string, mixed>                            $args
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function dispatch(GoogleCalendarClient $client, string $toolName, string $calendarId, array $args, bool $notifyDefault): array
    {
        return match ($toolName) {
            NativeTool::GcalListEvents->value => $this->listEvents($client, $calendarId, $args),
            NativeTool::GcalCreateEvent->value => $this->createEvent($client, $calendarId, $args, $notifyDefault),
            NativeTool::GcalUpdateEvent->value => $this->updateEvent($client, $calendarId, $args, $notifyDefault),
            NativeTool::GcalDeleteEvent->value => $this->deleteEvent($client, $calendarId, $args, $notifyDefault),
            NativeTool::GcalFindFreeSlots->value => $this->findFreeSlots($client, $calendarId, $args),
        };
    }

    /**
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function listEvents(GoogleCalendarClient $client, string $calendarId, array $args): array
    {
        $timeMin = $this->parseDate($args['time_min'] ?? null, fallback: now());
        $timeMax = $this->parseDate($args['time_max'] ?? null, fallback: now()->addDays(7));
        $query = isset($args['query']) ? (string) $args['query'] : null;
        $maxResults = isset($args['max_results']) ? (int) $args['max_results'] : 25;
        $timeZone = (string) ($args['time_zone'] ?? 'UTC');

        return $client->listEvents(
            calendarId: $calendarId,
            timeMin: $timeMin,
            timeMax: $timeMax,
            query: $query,
            maxResults: $maxResults,
            timeZone: $timeZone,
        );
    }

    /**
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function createEvent(GoogleCalendarClient $client, string $calendarId, array $args, bool $notifyDefault): array
    {
        $this->requireFields($args, ['summary', 'start', 'end'], NativeTool::GcalCreateEvent->value);

        $payload = [
            'summary' => (string) $args['summary'],
            'start' => $this->parseDate($args['start']),
            'end' => $this->parseDate($args['end']),
            'time_zone' => (string) ($args['time_zone'] ?? 'UTC'),
        ];

        foreach (['description', 'location'] as $field) {
            if (filled($args[$field] ?? null)) {
                $payload[$field] = (string) $args[$field];
            }
        }

        if (isset($args['attendees']) && is_array($args['attendees'])) {
            $payload['attendees'] = array_values(array_filter(
                array_map(fn (mixed $email): string => is_string($email) ? trim($email) : '', $args['attendees']),
                fn (string $email): bool => $email !== '',
            ));
        }

        $notify = array_key_exists('notify_attendees', $args)
            ? (bool) $args['notify_attendees']
            : $notifyDefault;

        return $client->createEvent($calendarId, $payload, $notify);
    }

    /**
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function updateEvent(GoogleCalendarClient $client, string $calendarId, array $args, bool $notifyDefault): array
    {
        $this->requireFields($args, ['event_id'], NativeTool::GcalUpdateEvent->value);

        $eventId = (string) $args['event_id'];
        $patch = [];

        foreach (['summary', 'description', 'location'] as $field) {
            if (array_key_exists($field, $args)) {
                $patch[$field] = (string) $args[$field];
            }
        }

        if (isset($args['start'])) {
            $patch['start'] = $this->parseDate($args['start']);
        }
        if (isset($args['end'])) {
            $patch['end'] = $this->parseDate($args['end']);
        }
        if (array_key_exists('time_zone', $args)) {
            $patch['time_zone'] = (string) $args['time_zone'];
        }
        if (isset($args['attendees']) && is_array($args['attendees'])) {
            $patch['attendees'] = array_values(array_filter(
                array_map(fn (mixed $email): string => is_string($email) ? trim($email) : '', $args['attendees']),
                fn (string $email): bool => $email !== '',
            ));
        }

        if ($patch === []) {
            throw ValidationException::withMessages([
                'args' => 'gcal_update_event precisa de pelo menos um campo para atualizar.',
            ]);
        }

        $notify = array_key_exists('notify_attendees', $args)
            ? (bool) $args['notify_attendees']
            : $notifyDefault;

        return $client->updateEvent($calendarId, $eventId, $patch, $notify);
    }

    /**
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function deleteEvent(GoogleCalendarClient $client, string $calendarId, array $args, bool $notifyDefault): array
    {
        $this->requireFields($args, ['event_id'], NativeTool::GcalDeleteEvent->value);

        $eventId = (string) $args['event_id'];
        $notify = array_key_exists('notify_attendees', $args)
            ? (bool) $args['notify_attendees']
            : $notifyDefault;

        $client->deleteEvent($calendarId, $eventId, $notify);

        return ['deleted' => true, 'event_id' => $eventId];
    }

    /**
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function findFreeSlots(GoogleCalendarClient $client, string $calendarId, array $args): array
    {
        $this->requireFields($args, ['duration_minutes', 'range_start', 'range_end'], NativeTool::GcalFindFreeSlots->value);

        $durationMinutes = max(5, (int) $args['duration_minutes']);
        $rangeStart = $this->parseDate($args['range_start']);
        $rangeEnd = $this->parseDate($args['range_end']);
        $timeZone = (string) ($args['time_zone'] ?? 'UTC');

        $calendarsToCheck = isset($args['calendar_ids']) && is_array($args['calendar_ids']) && $args['calendar_ids'] !== []
            ? array_values(array_filter(array_map('strval', $args['calendar_ids'])))
            : [$calendarId];

        $busy = $client->freeBusy($calendarsToCheck, $rangeStart, $rangeEnd, $timeZone);

        $mergedBusy = $this->mergeBusyWindows($busy);
        $slots = $this->buildFreeSlots($mergedBusy, $rangeStart, $rangeEnd, $durationMinutes);

        return [
            'duration_minutes' => $durationMinutes,
            'time_zone' => $timeZone,
            'free_slots' => $slots,
            'busy_per_calendar' => $busy,
        ];
    }

    /**
     * @param  array<string, list<array{start:string, end:string}>> $busyPerCalendar
     * @return list<array{start:string, end:string}>
     */
    private function mergeBusyWindows(array $busyPerCalendar): array
    {
        $all = [];
        foreach ($busyPerCalendar as $slots) {
            foreach ($slots as $slot) {
                $all[] = [
                    'start' => Carbon::parse($slot['start']),
                    'end' => Carbon::parse($slot['end']),
                ];
            }
        }

        if ($all === []) {
            return [];
        }

        usort($all, fn (array $a, array $b): int => $a['start']->timestamp <=> $b['start']->timestamp);

        $merged = [];
        $current = $all[0];
        for ($i = 1, $n = count($all); $i < $n; $i++) {
            if ($all[$i]['start']->lte($current['end'])) {
                if ($all[$i]['end']->gt($current['end'])) {
                    $current['end'] = $all[$i]['end'];
                }
            } else {
                $merged[] = ['start' => $current['start']->toIso8601String(), 'end' => $current['end']->toIso8601String()];
                $current = $all[$i];
            }
        }
        $merged[] = ['start' => $current['start']->toIso8601String(), 'end' => $current['end']->toIso8601String()];

        return $merged;
    }

    /**
     * @param  list<array{start:string, end:string}> $busyMerged
     * @return list<array{start:string, end:string}>
     */
    private function buildFreeSlots(array $busyMerged, Carbon $rangeStart, Carbon $rangeEnd, int $durationMinutes): array
    {
        $cursor = $rangeStart->copy();
        $free = [];

        foreach ($busyMerged as $slot) {
            $busyStart = Carbon::parse($slot['start']);
            $busyEnd = Carbon::parse($slot['end']);

            if ($busyStart->gt($cursor) && $cursor->diffInMinutes($busyStart, false) >= $durationMinutes) {
                $free[] = ['start' => $cursor->toIso8601String(), 'end' => $busyStart->toIso8601String()];
            }
            if ($busyEnd->gt($cursor)) {
                $cursor = $busyEnd->copy();
            }
        }

        if ($cursor->lt($rangeEnd) && $cursor->diffInMinutes($rangeEnd, false) >= $durationMinutes) {
            $free[] = ['start' => $cursor->toIso8601String(), 'end' => $rangeEnd->toIso8601String()];
        }

        return $free;
    }

    private function parseDate(mixed $value, ?Carbon $fallback = null): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (Throwable) {
                // fallthrough
            }
        }

        if ($fallback !== null) {
            return $fallback;
        }

        throw ValidationException::withMessages([
            'args' => "Data inválida ou ausente: '{$value}'. Use formato ISO 8601 (ex: 2026-06-01T10:00:00Z).",
        ]);
    }

    /**
     * @param array<string, mixed> $args
     * @param list<string>         $required
     */
    private function requireFields(array $args, array $required, string $tool): void
    {
        foreach ($required as $field) {
            if (! array_key_exists($field, $args) || $args[$field] === null || $args[$field] === '') {
                throw ValidationException::withMessages([
                    'args' => "{$tool}: campo obrigatório '{$field}' ausente.",
                ]);
            }
        }
    }

    private function assertToolKnown(string $tool): void
    {
        if (! in_array($tool, self::ALLOWED_TOOLS, true)) {
            throw ValidationException::withMessages([
                'tool_name' => "Tool '{$tool}' não é uma tool Google Calendar conhecida.",
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function loadRun(array $payload): AgentRun
    {
        $run = AgentRun::query()
            ->where('id', $payload['agent_run_id'])
            ->where('workspace_id', $payload['workspace_id'])
            ->where('agent_id', $payload['agent_id'])
            ->first();

        if (! $run instanceof AgentRun) {
            throw ValidationException::withMessages([
                'agent_run_id' => 'O agent_run não pertence a este workspace/agent.',
            ]);
        }

        return $run;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function loadSpecialist(array $payload): AgentSpecialist
    {
        $specialist = AgentSpecialist::query()
            ->where('id', $payload['specialist_id'])
            ->where('workspace_id', $payload['workspace_id'])
            ->where('agent_id', $payload['agent_id'])
            ->first();

        if (! $specialist instanceof AgentSpecialist) {
            throw ValidationException::withMessages([
                'specialist_id' => 'O specialist não pertence a este workspace/agent.',
            ]);
        }

        return $specialist;
    }

    private function assertToolAllowed(AgentSpecialist $specialist, string $tool): void
    {
        $allowlist = is_array($specialist->tools_allowlist) ? $specialist->tools_allowlist : [];

        if (! in_array($tool, $allowlist, true)) {
            throw ValidationException::withMessages([
                'tool_name' => "O specialist {$specialist->id} não tem '{$tool}' no allowlist.",
            ]);
        }
    }

    private function loadConnection(int $workspaceId, int $connectionId): GoogleCalendarConnection
    {
        if ($connectionId <= 0) {
            throw ValidationException::withMessages([
                'specialist_id' => 'Specialist não tem connection_id configurado em google_calendar_config.',
            ]);
        }

        $connection = GoogleCalendarConnection::query()
            ->where('id', $connectionId)
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->first();

        if (! $connection instanceof GoogleCalendarConnection) {
            throw ValidationException::withMessages([
                'specialist_id' => "Conexão Google Calendar #{$connectionId} não existe, está inativa ou pertence a outro workspace.",
            ]);
        }

        return $connection;
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>>|null $result
     */
    private function extractEventId(mixed $result): ?string
    {
        if (is_array($result) && isset($result['id']) && is_string($result['id'])) {
            return $result['id'];
        }
        if (is_array($result) && isset($result['event_id']) && is_string($result['event_id'])) {
            return $result['event_id'];
        }

        return null;
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>>|null $result
     */
    private function encodeResult(mixed $result): string
    {
        return (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed>                                 $args
     * @param array<string, mixed>|list<array<string, mixed>>|null $result
     */
    private function writeAudit(
        int $workspaceId,
        int $connectionId,
        int $agentRunId,
        int $specialistId,
        string $toolName,
        string $calendarId,
        ?string $eventId,
        array $args,
        bool $success,
        int $latencyMs,
        mixed $result,
        ?string $error,
    ): void {
        $excerpt = $result === null
            ? null
            : mb_substr((string) json_encode($result, JSON_UNESCAPED_UNICODE), 0, 500);

        GoogleCalendarAuditLog::query()->create([
            'workspace_id' => $workspaceId,
            'google_calendar_connection_id' => $connectionId,
            'agent_run_id' => $agentRunId,
            'specialist_id' => $specialistId,
            'action' => $toolName,
            'calendar_id' => $calendarId,
            'google_event_id' => $eventId,
            'request_args' => $args,
            'success' => $success,
            'latency_ms' => $latencyMs,
            'response_excerpt' => $excerpt,
            'error' => $error,
        ]);
    }
}
