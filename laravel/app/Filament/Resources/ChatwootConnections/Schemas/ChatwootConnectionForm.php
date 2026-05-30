<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChatwootConnections\Schemas;

use App\Enums\ChatwootConnectionStatus;
use App\Models\ChatwootConnection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ChatwootConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Configuração da conexão')
                    ->description(fn (string $operation): string => $operation === 'create'
                        ? 'Um Agent Bot será criado automaticamente no Chatwoot para este workspace. O bot é necessário para receber webhooks e ativar o agente. Após a criação, edite a conexão para informar o Admin API Token.'
                        : 'Dados da conexão Chatwoot deste workspace.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255),
                        Select::make('status')
                            ->label('Status')
                            ->options(self::statusOptions())
                            ->default(ChatwootConnectionStatus::Active->value)
                            ->required(),
                        TextInput::make('agent_bot_id')
                            ->label('Agent Bot')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (string $operation): bool => $operation === 'edit'),
                        TextInput::make('agent_bot_outgoing_url')
                            ->label('Webhook do Agent Bot')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (string $operation): bool => $operation === 'edit'),
                        TextInput::make('webhook_secret_status')
                            ->label('Webhook secret')
                            ->default(fn (?ChatwootConnection $record): string => filled($record?->webhook_secret)
                                ? 'Configurado'
                                : 'Não configurado')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (string $operation): bool => $operation === 'edit'),
                        TextInput::make('provisioning_error')
                            ->label('Erro de provisionamento')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (string $operation, ?ChatwootConnection $record): bool => $operation === 'edit'
                                && filled($record?->provisioning_error)),
                    ]),

                Section::make('Admin API Token')
                    ->description(
                        'O token do Agent Bot tem acesso limitado à API do Chatwoot — ele não pode editar contatos, sincronizar times nem labels. '
                        . 'Essas ações são assinadas pelo token informado aqui, que deve ser de um usuário com role Administrator.'
                        . "\n\n"
                        . 'Como obter: no Chatwoot acesse Profile Settings → Access Token (do usuário Administrator).'
                        . "\n\n"
                        . 'Dica: se quiser isolar as ações do sistema de um usuário real, crie um usuário dedicado no Chatwoot (ex: oryntra-admin@empresa.com) '
                        . 'com role Administrator e use o Access Token dele.'
                    )
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        TextInput::make('admin_api_token')
                            ->label('Admin API Token')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->placeholder(fn (?ChatwootConnection $record): string => $record?->hasAdminApiToken()
                                ? 'Token salvo (deixe em branco para manter)'
                                : 'Cole o User Access Token de um Administrator no Chatwoot')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(ChatwootConnectionStatus::cases())
            ->mapWithKeys(fn (ChatwootConnectionStatus $status): array => [$status->value => $status->label()])
            ->all();
    }
}
