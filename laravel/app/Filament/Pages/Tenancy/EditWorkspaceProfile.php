<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use DateTimeZone;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;

class EditWorkspaceProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Workspace';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome do workspace')
                    ->required()
                    ->maxLength(255),
                Select::make('timezone')
                    ->label('Fuso horario')
                    ->options(self::timezoneOptions())
                    ->searchable()
                    ->required()
                    ->default('UTC')
                    ->helperText('Usado para data/hora injetada no prompt da IA e exibicao no painel.'),
                Select::make('locale')
                    ->label('Idioma padrao')
                    ->options([
                        'en' => 'English',
                        'pt_BR' => 'Portugues (Brasil)',
                        'es' => 'Espanol',
                    ])
                    ->required()
                    ->default('en'),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function timezoneOptions(): array
    {
        $identifiers = DateTimeZone::listIdentifiers();

        return array_combine($identifiers, $identifiers) ?: ['UTC' => 'UTC'];
    }
}
