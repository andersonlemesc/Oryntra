<?php

declare(strict_types=1);

namespace App\Filament\Pages\Profile;

use App\Actions\Api\IssueApiToken;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ApiTokenAbilities;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ApiTokensPage extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?string $title = 'Tokens da API';

    protected static ?string $slug = 'account/api-tokens';

    protected string $view = 'filament.pages.profile.api-tokens';

    /**
     * Plain-text token shown exactly once after creation.
     */
    public ?string $plainTextToken = null;

    public static function getNavigationLabel(): string
    {
        return 'Tokens da API';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Only workspace managers (owner/admin, or super admin) may issue or view
     * API tokens. Agents/viewers cannot write via the API, so the page is hidden
     * and direct access to its URL is rejected.
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();
        $tenant = Filament::getTenant();

        return $user instanceof User
            && $tenant instanceof Workspace
            && $user->canManageWorkspace($tenant);
    }

    /**
     * @return array<int, ApiToken>
     */
    public function getTokens(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        return ApiToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->with('workspace')
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Gerar token')
                ->icon('heroicon-o-key')
                ->modalWidth(Width::ThreeExtraLarge)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->placeholder('Ex.: MCP no meu Claude')
                        ->required()
                        ->maxLength(120),
                    Select::make('workspace_id')
                        ->label('Workspace')
                        ->options($this->workspaceOptions())
                        ->required()
                        ->native(false),
                    CheckboxList::make('abilities')
                        ->label('Permissões')
                        ->options($this->abilityOptions())
                        ->descriptions($this->abilityDescriptions())
                        ->columns(3)
                        ->bulkToggleable()
                        ->required(),
                ])
                ->action(fn (array $data) => $this->createToken($data)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createToken(array $data): void
    {
        $user = Auth::user();
        $workspace = Workspace::query()->find($data['workspace_id']);

        if (! $user instanceof User || ! $workspace instanceof Workspace) {
            return;
        }

        try {
            $token = app(IssueApiToken::class)->execute(
                $user,
                $workspace,
                (string) $data['name'],
                array_values($data['abilities']),
            );
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Não foi possível gerar o token')
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->danger()
                ->send();

            return;
        }

        $this->plainTextToken = $token->plainTextToken;

        Notification::make()
            ->title('Token gerado')
            ->body('Copie agora — ele não será exibido novamente.')
            ->success()
            ->send();
    }

    public function revokeTokenAction(): Action
    {
        return Action::make('revokeToken')
            ->label('Revogar')
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('Revogar token')
            ->modalDescription('Aplicações que usam este token vão parar de funcionar imediatamente. Deseja continuar?')
            ->modalSubmitActionLabel('Revogar')
            ->action(function (array $arguments): void {
                $user = Auth::user();

                if (! $user instanceof User) {
                    return;
                }

                ApiToken::query()
                    ->where('tokenable_type', $user->getMorphClass())
                    ->where('tokenable_id', $user->getKey())
                    ->whereKey($arguments['token'] ?? null)
                    ->delete();

                Notification::make()->title('Token revogado')->success()->send();
            });
    }

    public function dismissPlainTextToken(): void
    {
        $this->plainTextToken = null;
    }

    /**
     * @return array<int, string>
     */
    private function workspaceOptions(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        $workspaces = $user->isSuperAdmin()
            ? Workspace::query()->orderBy('name')->get()
            : $user->workspaces;

        return $workspaces
            ->mapWithKeys(fn (Workspace $w): array => [$w->getKey() => $w->name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function abilityOptions(): array
    {
        return collect(ApiTokenAbilities::catalog())
            ->mapWithKeys(fn (array $a): array => [$a['value'] => $a['label']])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function abilityDescriptions(): array
    {
        return collect(ApiTokenAbilities::catalog())
            ->mapWithKeys(fn (array $a): array => [$a['value'] => $a['description']])
            ->all();
    }
}
