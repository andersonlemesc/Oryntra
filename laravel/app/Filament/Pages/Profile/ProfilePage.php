<?php

declare(strict_types=1);

namespace App\Filament\Pages\Profile;

use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Auth;

/**
 * @property-read Schema $form
 */
class ProfilePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?string $title = 'Meu perfil';

    protected static ?string $slug = 'account';

    protected string $view = 'filament.pages.profile.profile';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return 'Meu perfil';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $user = Auth::user();
        $workspace = Filament::getTenant();
        $membership = $user instanceof User && $workspace instanceof Workspace
            ? $this->currentMembership($user, $workspace)
            : null;

        $this->form->fill([
            'name' => $user?->name,
            'email' => $user?->email,
            'workspace_name' => $workspace instanceof Workspace ? $workspace->name : $this->emptyValue(),
            'workspace_role' => $this->formatWorkspaceRole($this->pivotString($membership, 'role')),
            'chatwoot_account_id' => $workspace instanceof Workspace
                ? $this->formatNullableInt($workspace->chatwoot_account_id)
                : $this->emptyValue(),
            'chatwoot_user_id' => $this->formatNullableInt($this->pivotInt($membership, 'chatwoot_user_id')),
            'chatwoot_role' => $this->formatNullableString($this->pivotString($membership, 'chatwoot_role')),
            'chatwoot_confirmed' => $this->formatBoolean($membership?->getAttribute('chatwoot_confirmed')),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identidade')
                    ->description('Nome e e-mail vêm do Chatwoot/sincronização da conta e não são editados por aqui.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('email')
                            ->label('E-mail')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2),

                Section::make('Workspace atual')
                    ->description('Como este usuário aparece no tenant selecionado.')
                    ->schema([
                        TextInput::make('workspace_name')
                            ->label('Workspace')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('workspace_role')
                            ->label('Permissão Oryntra')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('chatwoot_account_id')
                            ->label('Account ID Chatwoot')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(3),

                Section::make('Vínculo Chatwoot')
                    ->description('Dados sincronizados do agente/usuário no Chatwoot para este workspace.')
                    ->schema([
                        TextInput::make('chatwoot_user_id')
                            ->label('User ID Chatwoot')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('chatwoot_role')
                            ->label('Role Chatwoot')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('chatwoot_confirmed')
                            ->label('Confirmado no Chatwoot')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(3),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->mount();

        Notification::make()
            ->title('Perfil somente leitura')
            ->body('Nome, e-mail e vínculo Chatwoot são sincronizados pela conta Chatwoot.')
            ->info()
            ->send();
    }

    private function currentMembership(User $user, Workspace $workspace): ?Pivot
    {
        $member = $workspace->users()
            ->whereKey($user->getKey())
            ->first();

        $pivot = $member?->getRelationValue('pivot');

        return $pivot instanceof Pivot ? $pivot : null;
    }

    private function pivotString(?Pivot $pivot, string $key): ?string
    {
        $value = $pivot?->getAttribute($key);

        return is_string($value) && filled($value) ? $value : null;
    }

    private function pivotInt(?Pivot $pivot, string $key): ?int
    {
        $value = $pivot?->getAttribute($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function formatWorkspaceRole(?string $role): string
    {
        return match ($role) {
            'owner' => 'Owner',
            'admin' => 'Admin',
            'member' => 'Membro',
            'viewer' => 'Visualizador',
            default => $this->emptyValue(),
        };
    }

    private function formatBoolean(mixed $value): string
    {
        if ($value === null) {
            return $this->emptyValue();
        }

        return (bool) $value ? 'Sim' : 'Não';
    }

    private function formatNullableInt(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : $this->emptyValue();
    }

    private function formatNullableString(?string $value): string
    {
        return filled($value) ? $value : $this->emptyValue();
    }

    private function emptyValue(): string
    {
        return 'Não sincronizado';
    }
}
