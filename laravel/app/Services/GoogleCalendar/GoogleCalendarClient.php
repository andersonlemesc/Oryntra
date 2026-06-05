<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar;

use App\Models\GoogleCalendarConnection;
use App\Services\GoogleCalendar\Exceptions\GoogleCalendarException;
use Google\Client as GoogleClient;
use Google\Service\Calendar as CalendarService;
use Google\Service\Calendar\Event as CalendarEvent;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Wrapper por-conexão da Google Calendar API.
 *
 * Refresh lazy: ao construir, se access_token estiver expirado e tiver refresh_token,
 * renova via fetchAccessTokenWithRefreshToken e persiste o novo token na conexão.
 * Em invalid_grant (token revogado externamente), marca conexão is_active=false +
 * grava last_error e propaga GoogleCalendarException.
 */
class GoogleCalendarClient
{
    private GoogleClient $client;

    private CalendarService $service;

    public function __construct(
        private readonly GoogleCalendarConnection $connection,
        private readonly GoogleCalendarConfig $config,
    ) {
        $this->client = $this->buildClient();
        $this->service = new CalendarService($this->client);
    }

    public function connection(): GoogleCalendarConnection
    {
        return $this->connection;
    }

    /**
     * @return list<array{id:string, summary:string, primary:bool, access_role:string, time_zone:?string}>
     */
    public function listCalendars(): array
    {
        try {
            $list = $this->service->calendarList->listCalendarList();
        } catch (GoogleServiceException $e) {
            throw GoogleCalendarException::apiError('listCalendars', $e->getMessage(), $e->getCode());
        }

        $items = [];
        foreach ($list->getItems() as $cal) {
            $items[] = [
                'id' => (string) $cal->getId(),
                'summary' => (string) ($cal->getSummaryOverride() ?: $cal->getSummary()),
                'primary' => (bool) $cal->getPrimary(),
                'access_role' => (string) $cal->getAccessRole(),
                'time_zone' => $cal->getTimeZone(),
            ];
        }

        return $items;
    }

    /**
     * @return array{events: list<array<string, mixed>>, next_page_token: ?string}
     */
    public function listEvents(
        string $calendarId,
        Carbon $timeMin,
        Carbon $timeMax,
        ?string $query = null,
        int $maxResults = 50,
        ?string $pageToken = null,
        string $timeZone = 'UTC',
    ): array {
        $params = [
            'timeMin' => $timeMin->toRfc3339String(),
            'timeMax' => $timeMax->toRfc3339String(),
            'singleEvents' => true,
            'orderBy' => 'startTime',
            'maxResults' => max(1, min($maxResults, 250)),
            'timeZone' => $timeZone,
        ];

        if (filled($query)) {
            $params['q'] = $query;
        }

        if (filled($pageToken)) {
            $params['pageToken'] = $pageToken;
        }

        try {
            $response = $this->service->events->listEvents($calendarId, $params);
        } catch (GoogleServiceException $e) {
            throw GoogleCalendarException::apiError('listEvents', $e->getMessage(), $e->getCode());
        }

        $events = [];
        foreach ($response->getItems() as $event) {
            $events[] = $this->serializeEvent($event);
        }

        return [
            'events' => $events,
            'next_page_token' => $response->getNextPageToken(),
        ];
    }

    /**
     * @param  array{summary:string, start:Carbon, end:Carbon, time_zone?:string, description?:string, location?:string, attendees?:list<string>} $payload
     * @return array<string, mixed>
     */
    public function createEvent(string $calendarId, array $payload, bool $notifyAttendees = true): array
    {
        $event = $this->hydrateEvent(new CalendarEvent, $payload);

        try {
            $created = $this->service->events->insert($calendarId, $event, [
                'sendUpdates' => $notifyAttendees ? 'all' : 'none',
            ]);
        } catch (GoogleServiceException $e) {
            throw GoogleCalendarException::apiError('createEvent', $e->getMessage(), $e->getCode());
        }

        return $this->serializeEvent($created);
    }

    /**
     * @param  array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function updateEvent(string $calendarId, string $eventId, array $patch, bool $notifyAttendees = true): array
    {
        try {
            $existing = $this->service->events->get($calendarId, $eventId);
            $updated = $this->hydrateEvent($existing, $patch);
            $result = $this->service->events->update($calendarId, $eventId, $updated, [
                'sendUpdates' => $notifyAttendees ? 'all' : 'none',
            ]);
        } catch (GoogleServiceException $e) {
            throw GoogleCalendarException::apiError('updateEvent', $e->getMessage(), $e->getCode());
        }

        return $this->serializeEvent($result);
    }

    public function deleteEvent(string $calendarId, string $eventId, bool $notifyAttendees = true): void
    {
        try {
            $this->service->events->delete($calendarId, $eventId, [
                'sendUpdates' => $notifyAttendees ? 'all' : 'none',
            ]);
        } catch (GoogleServiceException $e) {
            throw GoogleCalendarException::apiError('deleteEvent', $e->getMessage(), $e->getCode());
        }
    }

    /**
     * @param  list<string>                                         $calendarIds
     * @return array<string, list<array{start:string, end:string}>>
     */
    public function freeBusy(array $calendarIds, Carbon $timeMin, Carbon $timeMax, string $timeZone = 'UTC'): array
    {
        $request = new FreeBusyRequest;
        $request->setTimeMin($timeMin->toRfc3339String());
        $request->setTimeMax($timeMax->toRfc3339String());
        $request->setTimeZone($timeZone);
        $request->setItems(array_map(function (string $id): FreeBusyRequestItem {
            $item = new FreeBusyRequestItem;
            $item->setId($id);

            return $item;
        }, $calendarIds));

        try {
            $response = $this->service->freebusy->query($request);
        } catch (GoogleServiceException $e) {
            throw GoogleCalendarException::apiError('freeBusy', $e->getMessage(), $e->getCode());
        }

        $result = [];
        foreach ($response->getCalendars() as $id => $cal) {
            $busy = [];
            foreach ($cal->getBusy() as $slot) {
                $busy[] = [
                    'start' => (string) $slot->getStart(),
                    'end' => (string) $slot->getEnd(),
                ];
            }
            $result[(string) $id] = $busy;
        }

        return $result;
    }

    /**
     * Revoga o token Google e marca conexão inativa. Idempotente.
     */
    public function revoke(): void
    {
        try {
            $this->client->revokeToken($this->connection->access_token);
        } catch (Throwable) {
            // ignora — pode estar já revogado
        }

        $this->connection->update([
            'is_active' => false,
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'last_error' => null,
        ]);
    }

    private function buildClient(): GoogleClient
    {
        $client = $this->config->newGoogleClient();

        $expiresIn = 0;
        if ($this->connection->expires_at instanceof Carbon) {
            $diff = (int) now()->diffInSeconds($this->connection->expires_at, false);
            $expiresIn = max(0, $diff);
        }

        $createdTimestamp = $this->connection->expires_at instanceof Carbon
            ? $this->connection->expires_at->copy()->subSeconds(3600)->timestamp
            : time() - 3600;

        $client->setAccessToken([
            'access_token' => (string) $this->connection->access_token,
            'refresh_token' => (string) $this->connection->refresh_token,
            'token_type' => $this->connection->token_type,
            'expires_in' => $expiresIn,
            'created' => $createdTimestamp,
            'scope' => implode(' ', $this->connection->scopes ?: []),
        ]);

        if ($client->isAccessTokenExpired()) {
            $this->refreshToken($client);
        }

        return $client;
    }

    private function refreshToken(GoogleClient $client): void
    {
        if (! $this->connection->hasRefreshToken()) {
            throw GoogleCalendarException::noRefreshToken();
        }

        try {
            $newToken = $client->fetchAccessTokenWithRefreshToken($this->connection->refresh_token);
        } catch (Throwable $e) {
            $this->markRevoked($e->getMessage());
            throw GoogleCalendarException::refreshFailed($e->getMessage());
        }

        if (isset($newToken['error'])) {
            $reason = (string) ($newToken['error_description'] ?? $newToken['error']);
            $this->markRevoked($reason);
            throw GoogleCalendarException::refreshFailed($reason);
        }

        $this->connection->forceFill([
            'access_token' => (string) $newToken['access_token'],
            'expires_at' => now()->addSeconds((int) ($newToken['expires_in'] ?? 3600)),
            'token_type' => (string) ($newToken['token_type'] ?? 'Bearer'),
            'last_error' => null,
        ])->save();
    }

    private function markRevoked(string $reason): void
    {
        $this->connection->forceFill([
            'is_active' => false,
            'last_error' => $reason,
        ])->save();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrateEvent(CalendarEvent $event, array $payload): CalendarEvent
    {
        $timeZone = (string) ($payload['time_zone'] ?? 'UTC');

        if (array_key_exists('summary', $payload)) {
            $event->setSummary((string) $payload['summary']);
        }

        if (array_key_exists('description', $payload)) {
            $event->setDescription((string) $payload['description']);
        }

        if (array_key_exists('location', $payload)) {
            $event->setLocation((string) $payload['location']);
        }

        if (array_key_exists('start', $payload) && $payload['start'] instanceof Carbon) {
            $start = new EventDateTime;
            $start->setDateTime($payload['start']->toRfc3339String());
            $start->setTimeZone($timeZone);
            $event->setStart($start);
        }

        if (array_key_exists('end', $payload) && $payload['end'] instanceof Carbon) {
            $end = new EventDateTime;
            $end->setDateTime($payload['end']->toRfc3339String());
            $end->setTimeZone($timeZone);
            $event->setEnd($end);
        }

        if (array_key_exists('attendees', $payload) && is_array($payload['attendees'])) {
            $event->setAttendees(array_map(function (string $email): EventAttendee {
                $attendee = new EventAttendee;
                $attendee->setEmail($email);

                return $attendee;
            }, $payload['attendees']));
        }

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEvent(CalendarEvent $event): array
    {
        $attendees = [];
        foreach ($event->getAttendees() ?? [] as $attendee) {
            $attendees[] = [
                'email' => (string) $attendee->getEmail(),
                'response_status' => (string) $attendee->getResponseStatus(),
                'organizer' => (bool) $attendee->getOrganizer(),
            ];
        }

        return [
            'id' => (string) $event->getId(),
            'status' => (string) $event->getStatus(),
            'summary' => (string) $event->getSummary(),
            'description' => $event->getDescription(),
            'location' => $event->getLocation(),
            'start' => $event->getStart()?->getDateTime() ?? $event->getStart()?->getDate(),
            'end' => $event->getEnd()?->getDateTime() ?? $event->getEnd()?->getDate(),
            'time_zone' => $event->getStart()?->getTimeZone(),
            'attendees' => $attendees,
            'html_link' => (string) $event->getHtmlLink(),
            'created' => $event->getCreated(),
            'updated' => $event->getUpdated(),
        ];
    }
}
