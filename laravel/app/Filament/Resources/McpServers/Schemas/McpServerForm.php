<?php

declare(strict_types=1);

namespace App\Filament\Resources\McpServers\Schemas;

use App\Enums\ExternalToolAuthType;
use App\Enums\ExternalToolKind;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class McpServerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('workspace_id')
                    ->default(fn (): ?int => Filament::getTenant()?->getKey()),
                Hidden::make('kind')
                    ->default(ExternalToolKind::Mcp->value),

                Section::make('Identidade')
                    ->columns(2)
                    ->schema([
                        TextInput::make('slug')
                            ->label('Slug do servidor')
                            ->required()
                            ->maxLength(120)
                            ->rule('regex:/^[a-z][a-z0-9_]*$/')
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('workspace_id', Filament::getTenant()?->getKey()),
                            )
                            ->helperText('Identificador snake_case unico no workspace. Vai para o allowlist do especialista. Ex: crm_n8n'),
                        TextInput::make('label')
                            ->label('Rotulo')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Nome amigavel exibido no admin.'),
                        Toggle::make('enabled')
                            ->label('Habilitado')
                            ->default(true),
                        Textarea::make('description')
                            ->label('Descricao para a IA')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('Texto que a IA le para entender o que este servidor MCP oferece. Inclua casos de uso.'),
                    ]),

                Section::make('Conexao')
                    ->columns(2)
                    ->schema([
                        TextInput::make('config.base_url')
                            ->label('URL do servidor MCP')
                            ->required()
                            ->maxLength(1000)
                            ->columnSpanFull()
                            ->helperText('Endpoint Streamable HTTP. Ex: https://n8n.example.com/mcp/abc123. Pode ser http interno.'),
                        TextInput::make('config.timeout_seconds')
                            ->label('Timeout (s)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(120)
                            ->placeholder('30')
                            ->helperText('Padrao 30s. Aplicado a cada chamada JSON-RPC.'),
                    ]),

                Section::make('Autenticacao')
                    ->columns(2)
                    ->schema([
                        Select::make('config.auth_type')
                            ->label('Tipo')
                            ->options([
                                ExternalToolAuthType::None->value => 'Nenhuma',
                                ExternalToolAuthType::Bearer->value => 'Bearer token',
                                ExternalToolAuthType::ApiKey->value => 'API Key (header)',
                            ])
                            ->default(ExternalToolAuthType::None->value)
                            ->live()
                            ->required(),
                        TextInput::make('config.auth_config.header_name')
                            ->label('Nome do header')
                            ->default('X-API-Key')
                            ->visible(fn (Get $get): bool => $get('config.auth_type') === ExternalToolAuthType::ApiKey->value),
                        TextInput::make('secret_token')
                            ->label('Token / API key')
                            ->password()
                            ->revealable()
                            ->dehydrated()
                            ->maxLength(2000)
                            ->visible(fn (Get $get): bool => in_array($get('config.auth_type'), [ExternalToolAuthType::ApiKey->value, ExternalToolAuthType::Bearer->value], true))
                            ->helperText('Armazenado criptografado. Em edicao, deixe em branco para manter o atual.'),
                    ]),
            ]);
    }
}
