<?php

declare(strict_types=1);

namespace App\Filament\Pages\Profile;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * @property-read Schema $passwordForm
 */
class SecurityPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?string $title = 'Segurança';

    protected static ?string $slug = 'account/security';

    protected string $view = 'filament.pages.profile.security';

    /**
     * @var array<string, mixed>
     */
    public array $passwordData = [];

    public string $confirmationCode = '';

    public static function getNavigationLabel(): string
    {
        return 'Segurança';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->passwordForm->fill();
    }

    public function passwordForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('current_password')
                    ->label('Senha atual')
                    ->password()
                    ->revealable()
                    ->required(),
                TextInput::make('password')
                    ->label('Nova senha')
                    ->password()
                    ->revealable()
                    ->required()
                    ->confirmed(),
                TextInput::make('password_confirmation')
                    ->label('Confirmar nova senha')
                    ->password()
                    ->revealable()
                    ->required(),
            ])
            ->statePath('passwordData');
    }

    public function updatePassword(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        try {
            app(UpdatesUserPasswords::class)->update($user, $this->passwordForm->getState());
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Não foi possível trocar a senha')
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->danger()
                ->send();

            return;
        }

        $this->passwordForm->fill();

        Notification::make()->title('Senha atualizada')->success()->send();
    }

    public function enableTwoFactor(EnableTwoFactorAuthentication $enable): void
    {
        $user = $this->user();

        // force: true rotates the secret on every (re)start, so a pending,
        // never-confirmed setup always yields a fresh QR code instead of
        // re-showing the same stale one.
        $enable($user, force: true);

        $this->confirmationCode = '';

        Notification::make()
            ->title('2FA iniciado')
            ->body('Escaneie o QR code e confirme com um código para concluir.')
            ->success()
            ->send();
    }

    public function cancelTwoFactorSetup(DisableTwoFactorAuthentication $disable): void
    {
        $disable($this->user());

        $this->confirmationCode = '';

        Notification::make()->title('Configuração de 2FA cancelada')->success()->send();
    }

    public function confirmTwoFactor(ConfirmTwoFactorAuthentication $confirm): void
    {
        $user = $this->user();

        try {
            $confirm($user, $this->confirmationCode);
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Código inválido')
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->danger()
                ->send();

            return;
        }

        $this->confirmationCode = '';

        Notification::make()->title('2FA ativado')->success()->send();
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generate): void
    {
        $generate($this->user());

        Notification::make()->title('Códigos de recuperação regenerados')->success()->send();
    }

    public function disableTwoFactorAction(): Action
    {
        return Action::make('disableTwoFactor')
            ->label('Desativar 2FA')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Desativar 2FA')
            ->modalDescription('Sua conta ficará menos protegida sem o segundo fator. Deseja continuar?')
            ->modalSubmitActionLabel('Desativar')
            ->action(function (): void {
                app(DisableTwoFactorAuthentication::class)($this->user());

                Notification::make()->title('2FA desativado')->success()->send();
            });
    }

    public function twoFactorEnabled(): bool
    {
        return ! is_null($this->user()->two_factor_secret);
    }

    public function twoFactorConfirmed(): bool
    {
        return ! is_null($this->user()->two_factor_confirmed_at);
    }

    public function qrCodeSvg(): ?string
    {
        $user = $this->user();

        return $user->two_factor_secret ? $user->twoFactorQrCodeSvg() : null;
    }

    /**
     * @return array<int, string>
     */
    public function recoveryCodes(): array
    {
        $user = $this->user();

        if (is_null($user->two_factor_secret)) {
            return [];
        }

        return $user->recoveryCodes();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
