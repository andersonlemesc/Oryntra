<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Jobs\Chatwoot\SyncChatwootAccountsJob;
use App\Models\ChatwootPlatformConnection;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * @property-read Schema $form
 */
class ChatwootPlatformSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?string $title = 'Conexão Chatwoot Platform';

    protected static ?string $slug = 'platform/chatwoot';

    protected string $view = 'filament.pages.chatwoot-platform-settings';

    public static function getNavigationLabel(): string
    {
        return 'Chatwoot Platform';
    }

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public function mount(): void
    {
        $connection = ChatwootPlatformConnection::current();

        $this->form->fill([
            'base_url' => $connection->base_url,
            'platform_token' => $connection->platform_token,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('base_url')
                    ->label('Base URL do Chatwoot')
                    ->placeholder('https://chatwoot.exemplo.com')
                    ->url()
                    ->required(),
                TextInput::make('platform_token')
                    ->label('Platform App Token')
                    ->helperText('Gerado no Super Admin Console → Platform Apps. Armazenado criptografado.')
                    ->password()
                    ->revealable()
                    ->required(),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Salvar')
                ->action('save'),

            Action::make('sync')
                ->label('Sincronizar agora')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Vai listar accounts + users do Chatwoot e atualizar Workspaces e usuários.')
                ->visible(fn () => ChatwootPlatformConnection::current()->isConfigured())
                ->action('runSync'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $connection = ChatwootPlatformConnection::current();
        $connection->fill([
            'base_url' => $data['base_url'],
            'platform_token' => $data['platform_token'],
        ])->save();

        Notification::make()
            ->title('Configuração salva')
            ->success()
            ->send();
    }

    public function runSync(): void
    {
        SyncChatwootAccountsJob::dispatch();

        Notification::make()
            ->title('Sincronização enfileirada')
            ->body('Acompanhe o status na fila Horizon ou recarregue a página em alguns segundos.')
            ->success()
            ->send();
    }
}
