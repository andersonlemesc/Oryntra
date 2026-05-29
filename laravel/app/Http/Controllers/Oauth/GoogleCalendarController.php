<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oauth;

use App\Models\GoogleCalendarConnection;
use App\Models\Workspace;
use App\Services\GoogleCalendar\GoogleCalendarConfig;
use Google\Client as GoogleClient;
use Google\Service\Oauth2;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GoogleCalendarController
{
    private const SESSION_STATE_KEY = 'google_calendar_oauth_state';

    public function initiate(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorizeWorkspaceMember($request, $workspace);

        $config = GoogleCalendarConfig::fromConfig();

        $state = (string) Str::uuid();
        $request->session()->put(self::SESSION_STATE_KEY, [
            'state' => $state,
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->getKey(),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ]);

        return redirect()->away($config->buildAuthUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $session = $request->session()->pull(self::SESSION_STATE_KEY);

        if (! is_array($session) || ! isset($session['state'], $session['workspace_id'], $session['user_id'])) {
            return $this->failureRedirect(null, 'Estado OAuth inválido ou ausente. Tente reconectar.');
        }

        if (($session['expires_at'] ?? 0) < now()->timestamp) {
            return $this->failureRedirect($session['workspace_id'] ?? null, 'Tempo expirado. Tente reconectar.');
        }

        if (! hash_equals((string) $session['state'], (string) $request->query('state'))) {
            return $this->failureRedirect($session['workspace_id'], 'State CSRF não confere.');
        }

        if ($error = $request->query('error')) {
            return $this->failureRedirect($session['workspace_id'], "Google rejeitou: {$error}");
        }

        $code = (string) $request->query('code');
        if (blank($code)) {
            return $this->failureRedirect($session['workspace_id'], 'Code de autorização ausente.');
        }

        try {
            $workspace = Workspace::query()->findOrFail($session['workspace_id']);
            $config = GoogleCalendarConfig::fromConfig();
            $client = $config->newGoogleClient();
            $token = $client->fetchAccessTokenWithAuthCode($code);
        } catch (Throwable $e) {
            Log::error('Google Calendar OAuth token exchange falhou', ['exception' => $e->getMessage()]);

            return $this->failureRedirect($session['workspace_id'], 'Falha ao trocar code por tokens: ' . $e->getMessage());
        }

        if (isset($token['error'])) {
            $reason = (string) ($token['error_description'] ?? $token['error']);

            return $this->failureRedirect($workspace->id, "Google rejeitou troca de tokens: {$reason}");
        }

        if (! isset($token['refresh_token'])) {
            return $this->failureRedirect(
                $workspace->id,
                'Google não retornou refresh_token. Revogue o acesso em https://myaccount.google.com/permissions e tente novamente.'
            );
        }

        $profile = $this->fetchProfile($client);

        if (blank($profile['email'])) {
            return $this->failureRedirect($workspace->id, 'Não foi possível obter o email Google da conexão.');
        }

        $connection = $this->persistConnection(
            workspace: $workspace,
            userId: (int) $session['user_id'],
            token: $token,
            profile: $profile,
        );

        return redirect()
            ->to($this->successUrl($workspace, $connection))
            ->with('success', "Google Calendar conectado: {$connection->google_email}");
    }

    /**
     * @param array<string, mixed>              $token
     * @param array{email:?string, sub:?string} $profile
     */
    private function persistConnection(Workspace $workspace, int $userId, array $token, array $profile): GoogleCalendarConnection
    {
        $expiresIn = (int) ($token['expires_in'] ?? 3600);
        $scopes = isset($token['scope']) && is_string($token['scope'])
            ? array_values(array_filter(explode(' ', $token['scope'])))
            : GoogleCalendarConfig::DEFAULT_SCOPES;

        $existing = GoogleCalendarConnection::query()
            ->withTrashed()
            ->where('workspace_id', $workspace->id)
            ->where('google_email', $profile['email'])
            ->first();

        $attributes = [
            'workspace_id' => $workspace->id,
            'label' => $existing?->label ?? $this->defaultLabel($profile['email']),
            'google_email' => $profile['email'],
            'google_user_id' => $profile['sub'],
            'access_token' => (string) $token['access_token'],
            'refresh_token' => (string) $token['refresh_token'],
            'token_type' => (string) ($token['token_type'] ?? 'Bearer'),
            'expires_at' => now()->addSeconds($expiresIn),
            'scopes' => $scopes,
            'default_calendar_id' => $existing?->default_calendar_id ?? 'primary',
            'is_active' => true,
            'last_error' => null,
            'created_by_user_id' => $userId,
        ];

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->forceFill($attributes)->save();

            return $existing->refresh();
        }

        return GoogleCalendarConnection::query()->create($attributes);
    }

    /**
     * @return array{email:?string, sub:?string}
     */
    private function fetchProfile(GoogleClient $client): array
    {
        try {
            $oauth = new Oauth2($client);
            $info = $oauth->userinfo->get();

            return [
                'email' => $info->getEmail(),
                'sub' => $info->getId(),
            ];
        } catch (Throwable $e) {
            Log::warning('Google Calendar userinfo falhou', ['exception' => $e->getMessage()]);

            return ['email' => null, 'sub' => null];
        }
    }

    private function authorizeWorkspaceMember(Request $request, Workspace $workspace): void
    {
        $user = $request->user();
        $isMember = $workspace->users()->whereKey($user->getKey())->exists();

        if (! $isMember) {
            throw new AuthorizationException('Você não é membro deste workspace.');
        }
    }

    private function defaultLabel(string $email): string
    {
        return "Google Calendar — {$email}";
    }

    private function successUrl(Workspace $workspace, GoogleCalendarConnection $connection): string
    {
        return url("/admin/{$workspace->id}/google-calendar-connections/{$connection->id}/edit");
    }

    private function failureRedirect(?int $workspaceId, string $message): RedirectResponse
    {
        $target = $workspaceId
            ? url("/admin/{$workspaceId}/google-calendar-connections")
            : url('/admin');

        return redirect()->to($target)->with('error', "Google Calendar: {$message}");
    }
}
