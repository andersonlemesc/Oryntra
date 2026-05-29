<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar\Exceptions;

use RuntimeException;

class GoogleCalendarException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Google Calendar OAuth não está configurado (GOOGLE_CALENDAR_CLIENT_ID/SECRET/REDIRECT_URI ausentes).');
    }

    public static function refreshFailed(string $reason): self
    {
        return new self("Falha ao renovar access_token Google Calendar: {$reason}");
    }

    public static function noRefreshToken(): self
    {
        return new self('Conexão Google Calendar não possui refresh_token. Necessário reconectar com prompt=consent.');
    }

    public static function apiError(string $action, string $reason, int $code = 0): self
    {
        return new self("Google Calendar API error em '{$action}': {$reason}", $code);
    }
}
