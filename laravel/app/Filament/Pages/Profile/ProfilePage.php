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
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

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

        $this->form->fill([
            'name' => $user?->name,
            'email' => $user?->email,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('E-mail')
                    ->email()
                    ->required()
                    ->maxLength(255),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Salvar')->action('save'),
        ];
    }

    public function save(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        try {
            app(UpdatesUserProfileInformation::class)->update($user, $this->form->getState());
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Não foi possível salvar')
                ->body(collect($e->errors())->flatten()->implode(' '))
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title('Perfil atualizado')->success()->send();
    }
}
