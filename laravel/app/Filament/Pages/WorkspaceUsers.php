<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Invitations\SendUserInvitation;
use App\Models\User;
use App\Models\UserInvitation;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Throwable;

class WorkspaceUsers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?string $title = 'Usuários';

    protected static ?string $slug = 'workspace/users';

    protected string $view = 'filament.pages.workspace-users';

    public static function getNavigationLabel(): string
    {
        return 'Usuários';
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-users';
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();
        $tenant = Filament::getTenant();

        return $user instanceof User
            && $tenant instanceof Workspace
            && $user->canManageWorkspace($tenant);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->membersQuery())
            ->defaultSort('users.name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(['users.name', 'users.email'])
                    ->sortable()
                    ->description(fn (User $record): string => $record->email),
                TextColumn::make('ws_role')
                    ->label('Papel')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'owner' => 'Owner',
                        'admin' => 'Admin',
                        'member' => 'Agente',
                        'viewer' => 'Viewer',
                        default => (string) $state,
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'owner', 'admin' => 'success',
                        'member' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('confirmed')
                    ->label('Confirmado')
                    ->badge()
                    ->state(fn (User $record): string => $record->email_verified_at !== null ? 'Sim' : 'Não')
                    ->color(fn (string $state): string => $state === 'Sim' ? 'success' : 'gray'),
                TextColumn::make('active')
                    ->label('Ativo')
                    ->badge()
                    ->state(fn (User $record): string => $this->isActive($record) ? 'Sim' : 'Não')
                    ->color(fn (string $state): string => $state === 'Sim' ? 'success' : 'warning'),
                TextColumn::make('invite_status')
                    ->label('Convite')
                    ->badge()
                    ->state(fn (User $record): string => $this->inviteStatusLabel($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Aceito' => 'success',
                        'Pendente' => 'warning',
                        'Expirado' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('cw_role')
                    ->label('Chatwoot')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->recordActions([
                $this->resendInvitationAction(),
                $this->resetPasswordAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->bulkInviteAction(),
                ]),
            ]);
    }

    private function membersQuery(): Builder
    {
        $tenant = Filament::getTenant();
        $tenantId = $tenant instanceof Workspace ? (int) $tenant->getKey() : 0;

        return User::query()
            ->join('workspace_members', 'workspace_members.user_id', '=', 'users.id')
            ->where('workspace_members.workspace_id', $tenantId)
            ->select([
                'users.*',
                'workspace_members.role as ws_role',
                'workspace_members.chatwoot_role as cw_role',
                'workspace_members.chatwoot_confirmed as cw_confirmed',
            ])
            ->with('latestInvitation');
    }

    private function isActive(User $user): bool
    {
        // "Ativo" = conta utilizável / onboarding concluído. Super admins (e
        // demais auto-registrados) já nascem verificados; convidados ficam
        // ativos ao aceitar o convite. Só usuários da sync sem convite aceito
        // permanecem inativos.
        return $user->isSuperAdmin()
            || $user->email_verified_at !== null
            || ($user->latestInvitation?->isAccepted() ?? false);
    }

    private function inviteStatusLabel(User $user): string
    {
        $invitation = $user->latestInvitation;

        if (! $invitation instanceof UserInvitation) {
            return 'Nenhum';
        }

        if ($invitation->isAccepted()) {
            return 'Aceito';
        }

        return $invitation->isExpired() ? 'Expirado' : 'Pendente';
    }

    private function resendInvitationAction(): Action
    {
        return Action::make('resendInvitation')
            ->label('Reenviar convite')
            ->icon('heroicon-o-envelope')
            ->visible(fn (User $record): bool => ! $this->isActive($record))
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => "Enviar convite de acesso para {$record->email}?")
            ->action(function (User $record): void {
                try {
                    app(SendUserInvitation::class)->execute(
                        $record,
                        invitedBy: Auth::user(),
                        source: 'manual',
                    );

                    Notification::make()
                        ->title('Convite enviado')
                        ->body("Convite enviado para {$record->email}.")
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Falha ao enviar convite')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private function resetPasswordAction(): Action
    {
        return Action::make('resetPassword')
            ->label('Reset de senha')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->visible(fn (User $record): bool => $record->email_verified_at !== null)
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => "Enviar link de redefinição de senha para {$record->email}?")
            ->action(function (User $record): void {
                $status = Password::sendResetLink(['email' => $record->email]);

                if ($status === Password::RESET_LINK_SENT) {
                    Notification::make()
                        ->title('Link enviado')
                        ->body("Link de redefinição enviado para {$record->email}.")
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Falha ao enviar link')
                    ->body((string) __($status))
                    ->danger()
                    ->send();
            });
    }

    private function bulkInviteAction(): BulkAction
    {
        return BulkAction::make('bulkInvite')
            ->label('Enviar convites')
            ->icon('heroicon-o-envelope')
            ->requiresConfirmation()
            ->modalDescription('Enviar convite de acesso para todos os usuários selecionados?')
            ->action(function (Collection $records): void {
                $action = app(SendUserInvitation::class);
                $invitedBy = Auth::user();
                $sent = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    // Usuários já ativos não precisam de convite.
                    if ($this->isActive($record)) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $action->execute($record, invitedBy: $invitedBy, source: 'manual');
                        $sent++;
                    } catch (Throwable $e) {
                        // Ignora individual; resumo informa o total enviado.
                    }
                }

                $body = "{$sent} convite(s) enviado(s).";
                if ($skipped > 0) {
                    $body .= " {$skipped} já ativo(s) ignorado(s).";
                }

                Notification::make()
                    ->title('Convites enviados')
                    ->body($body)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
