<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Jobs\Chatwoot\SyncChatwootAccountsJob;
use App\Models\ChatwootPlatformConnection;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PlatformSetupController extends Controller
{
    public function show(): View
    {
        $connection = ChatwootPlatformConnection::current();

        return view('setup.platform', [
            'baseUrl' => old('base_url', $connection->base_url ?? ''),
            'lastError' => $connection->last_sync_error,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'base_url' => ['required', 'url', 'max:255'],
            'platform_token' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $connection = ChatwootPlatformConnection::current();
        $connection->fill([
            'base_url' => $data['base_url'],
            'platform_token' => $data['platform_token'],
        ])->save();

        try {
            (new SyncChatwootAccountsJob)->handle();
        } catch (Throwable $e) {
            Log::warning('PlatformSetup sync failed', [
                'error' => $e->getMessage(),
                'base_url' => $data['base_url'],
            ]);

            return redirect()
                ->route('setup.platform.show')
                ->withInput($request->only('base_url'))
                ->withErrors([
                    'base_url' => $this->humanizeSyncError($e->getMessage()),
                ]);
        }

        // Connected, but the sync created no workspace (e.g. the account is not
        // a permissible of this Platform App). Send the operator back to the
        // setup screen with a clear message instead of a dead /admin 404.
        if (! Workspace::query()->exists()) {
            return redirect()
                ->route('setup.platform.show')
                ->withInput($request->only('base_url'))
                ->withErrors([
                    'platform_token' => 'Conexão OK, mas nenhuma conta foi encontrada para este Platform App. '
                        . 'Confirme no Chatwoot que a conta é permissible deste app (Super Admin → Platform Apps) e tente novamente.',
                ]);
        }

        return redirect('/admin')->with('status', 'Plataforma configurada e sincronizada.');
    }

    private function humanizeSyncError(string $rawMessage): string
    {
        if (str_contains($rawMessage, 'HTTP 404')) {
            return 'Nao foi possivel encontrar a API do Chatwoot nesta URL. Confira se a URL base aponta para o servidor Chatwoot (ex: http://host.docker.internal:3000) e nao para o proprio Oryntra.';
        }

        if (str_contains($rawMessage, 'HTTP 401') || str_contains($rawMessage, 'HTTP 403')) {
            return 'Token Platform invalido ou sem permissao. Gere um novo em Super Admin Console -> Platform Apps.';
        }

        if (str_contains($rawMessage, 'cURL error') || str_contains($rawMessage, 'Could not resolve')) {
            return 'Nao consegui conectar ao Chatwoot nesta URL. Verifique se o servico esta acessivel a partir do container.';
        }

        return "Falha ao sincronizar com Chatwoot: {$rawMessage}";
    }
}
