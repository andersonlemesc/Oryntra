<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentLlmKeys\Schemas;

use App\Enums\AgentLlmKeyStatus;
use App\Enums\AgentLlmProvider;
use App\Models\AgentLlmKey;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AgentLlmKeyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identidade')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Identificador interno. Ex: "OpenAI Producao", "Anthropic Dev".'),
                        Select::make('provider')
                            ->label('Provider')
                            ->options(self::providerOptions())
                            ->default(AgentLlmProvider::OpenAI->value)
                            ->required(),
                        Select::make('status')
                            ->label('Status')
                            ->options(self::statusOptions())
                            ->default(AgentLlmKeyStatus::Active->value)
                            ->required(),
                    ]),

                Section::make('Credencial')
                    ->schema([
                        TextInput::make('api_key')
                            ->label('API Key')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(512)
                            ->helperText(fn (?AgentLlmKey $record): string => $record !== null
                                ? 'Deixe em branco para manter a chave atual. Preencha para sobrescrever.'
                                : 'Armazenada criptografada (Laravel encrypted cast).')
                            ->placeholder('sk-...')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function providerOptions(): array
    {
        return collect(AgentLlmProvider::cases())
            ->mapWithKeys(fn (AgentLlmProvider $p): array => [$p->value => $p->label()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(AgentLlmKeyStatus::cases())
            ->mapWithKeys(fn (AgentLlmKeyStatus $s): array => [$s->value => $s->label()])
            ->all();
    }
}
