<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChatwootConnections\Pages;

use App\Filament\Resources\ChatwootConnections\ChatwootConnectionResource;
use App\Jobs\Chatwoot\ProvisionChatwootAgentBotJob;
use App\Models\ChatwootConnection;
use App\Models\ChatwootPlatformConnection;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateChatwootConnection extends CreateRecord
{
    protected static string $resource = ChatwootConnectionResource::class;

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Workspace || ! $tenant->chatwoot_account_id) {
            throw ValidationException::withMessages([
                'name' => 'Este workspace ainda não está vinculado a uma account do Chatwoot. Rode a sincronização primeiro.',
            ]);
        }

        $platformConnection = ChatwootPlatformConnection::current();

        if (! $platformConnection->exists || ! $platformConnection->isConfigured()) {
            throw ValidationException::withMessages([
                'name' => 'Configure a conexão Platform do Chatwoot antes de criar o robô.',
            ]);
        }

        $baseUrl = rtrim((string) $platformConnection->base_url, '/');

        $alreadyExists = ChatwootConnection::query()
            ->where('workspace_id', $tenant->id)
            ->where('base_url', $baseUrl)
            ->where('account_id', $tenant->chatwoot_account_id)
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'name' => 'Este workspace já possui uma conexão Chatwoot para esta conta.',
            ]);
        }

        $data['account_id'] = $tenant->chatwoot_account_id;
        $data['base_url'] = $baseUrl;
        $data['api_access_token'] = null;

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();
        assert($record instanceof ChatwootConnection);

        ProvisionChatwootAgentBotJob::dispatch($record->id)->afterCommit();

        Notification::make()
            ->title('Robô Chatwoot em provisionamento')
            ->body('O Agent Bot será criado no Chatwoot em segundo plano. Após o provisionamento, edite a conexão e informe o Admin API Token para habilitar sincronização de times, labels e edição de contatos.')
            ->success()
            ->send();
    }
}
