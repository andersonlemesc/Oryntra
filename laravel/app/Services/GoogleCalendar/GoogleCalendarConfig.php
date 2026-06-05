<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar;

use App\Services\GoogleCalendar\Exceptions\GoogleCalendarException;
use Google\Client as GoogleClient;

/**
 * Configuração centralizada (Client ID/Secret/Redirect URI) carregada do .env.
 *
 * É single-tenant no nível do APP: 1 Oryntra → 1 par de credenciais OAuth
 * registradas no Google Cloud. A tenancy fica nos tokens por conexão
 * (`GoogleCalendarConnection`), não nas credenciais do app.
 */
class GoogleCalendarConfig
{
    /** @var list<string> */
    public const DEFAULT_SCOPES = [
        'https://www.googleapis.com/auth/calendar',
        'https://www.googleapis.com/auth/calendar.events',
        'openid',
        'email',
        'profile',
    ];

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $redirectUri,
        public readonly string $applicationName = 'Oryntra',
        public readonly array $scopes = self::DEFAULT_SCOPES,
    ) {}

    public static function fromConfig(): self
    {
        $clientId = (string) config('services.google_calendar.client_id');
        $clientSecret = (string) config('services.google_calendar.client_secret');
        $redirectUri = (string) config('services.google_calendar.redirect_uri');

        if (blank($clientId) || blank($clientSecret) || blank($redirectUri)) {
            throw GoogleCalendarException::notConfigured();
        }

        return new self(
            clientId: $clientId,
            clientSecret: $clientSecret,
            redirectUri: $redirectUri,
            applicationName: (string) config('services.google_calendar.application_name', 'Oryntra'),
        );
    }

    public function isConfigured(): bool
    {
        return filled(config('services.google_calendar.client_id'))
            && filled(config('services.google_calendar.client_secret'))
            && filled(config('services.google_calendar.redirect_uri'));
    }

    /**
     * Builda um Google\Client cru (sem token) já com credenciais + escopos + access_type=offline.
     * Usado tanto pelo OAuth flow quanto pelo per-connection client.
     */
    public function newGoogleClient(): GoogleClient
    {
        $client = new GoogleClient;
        $client->setApplicationName($this->applicationName);
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        $client->setScopes($this->scopes);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    public function buildAuthUrl(string $state): string
    {
        $client = $this->newGoogleClient();
        $client->setState($state);

        return $client->createAuthUrl();
    }
}
