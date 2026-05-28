<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExternalTools\Schemas;

use App\Enums\ExternalToolAuthType;
use App\Enums\ExternalToolParamLocation;
use App\Services\AgentTools\ExternalToolSchemaBuilder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class ExternalToolForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('workspace_id')
                    ->default(fn (): ?int => Filament::getTenant()?->getKey()),
                Hidden::make('kind')->default('http_connector'),

                Section::make('Identidade')
                    ->columns(2)
                    ->schema([
                        TextInput::make('slug')
                            ->label('Slug da tool')
                            ->required()
                            ->maxLength(120)
                            ->rule('regex:/^[a-z][a-z0-9_]*$/')
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('workspace_id', Filament::getTenant()?->getKey()),
                            )
                            ->helperText('Nome que a IA usa para chamar a tool. Ex: query_order_status. snake_case, unico no workspace.'),
                        TextInput::make('label')
                            ->label('Rotulo')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Nome amigavel exibido no admin.'),
                        Toggle::make('enabled')
                            ->label('Habilitada')
                            ->default(true),
                        Textarea::make('description')
                            ->label('Descricao para a IA')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('Texto que a IA le para decidir quando chamar esta tool. Seja claro sobre o que ela faz.'),
                    ]),

                Section::make('Requisicao HTTP')
                    ->columns(2)
                    ->schema([
                        Select::make('config.http_method')
                            ->label('Metodo')
                            ->options([
                                'GET' => 'GET',
                                'POST' => 'POST',
                                'PUT' => 'PUT',
                                'PATCH' => 'PATCH',
                                'DELETE' => 'DELETE',
                            ])
                            ->default('GET')
                            ->required()
                            ->helperText('Metodos de escrita (POST/PUT/PATCH/DELETE) executam direto, sem aprovacao humana.'),
                        TextInput::make('config.timeout_seconds')
                            ->label('Timeout (s)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(60)
                            ->placeholder('10')
                            ->helperText('Padrao 10s. GET refaz a chamada 2x em caso de falha; metodos de escrita nunca.'),
                        TextInput::make('config.base_url')
                            ->label('Base URL')
                            ->required()
                            ->maxLength(1000)
                            ->columnSpanFull()
                            ->helperText('Fixa pelo admin. A IA nunca controla a URL/host. Pode ser uma API interna (http).'),
                        TextInput::make('config.path')
                            ->label('Caminho')
                            ->maxLength(1000)
                            ->columnSpanFull()
                            ->helperText('Anexado a base. Use {nome} para inserir parametros de caminho. Ex: /orders/{order_id}'),
                    ]),

                Section::make('Autenticacao')
                    ->columns(2)
                    ->schema([
                        Select::make('config.auth_type')
                            ->label('Tipo')
                            ->options(ExternalToolAuthType::options())
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
                        TextInput::make('secret_username')
                            ->label('Usuario')
                            ->dehydrated()
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('config.auth_type') === ExternalToolAuthType::Basic->value),
                        TextInput::make('secret_password')
                            ->label('Senha')
                            ->password()
                            ->revealable()
                            ->dehydrated()
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('config.auth_type') === ExternalToolAuthType::Basic->value)
                            ->helperText('Armazenada criptografada. Em edicao, deixe em branco para manter a atual.'),
                    ]),

                Section::make('Parametros que a IA preenche')
                    ->schema([
                        Toggle::make('advanced_schema')
                            ->label('Modo avancado (JSON)')
                            ->live()
                            ->default(false)
                            ->helperText('Edita o param_schema diretamente como JSON em vez do construtor visual.'),
                        Repeater::make('param_rows')
                            ->hiddenLabel()
                            ->visible(fn (Get $get): bool => ! $get('advanced_schema'))
                            ->addActionLabel('Adicionar parametro')
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                            ->columns(2)
                            ->defaultItems(0)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Nome')
                                    ->required()
                                    ->rule('regex:/^[a-z][a-z0-9_]*$/')
                                    ->helperText('snake_case.'),
                                Select::make('type')
                                    ->label('Tipo')
                                    ->options(array_combine(ExternalToolSchemaBuilder::TYPES, ExternalToolSchemaBuilder::TYPES))
                                    ->default('string')
                                    ->required(),
                                Select::make('location')
                                    ->label('Destino')
                                    ->options(ExternalToolParamLocation::options())
                                    ->default(ExternalToolParamLocation::Query->value)
                                    ->required(),
                                Toggle::make('required')
                                    ->label('Obrigatorio')
                                    ->default(false),
                                Textarea::make('description')
                                    ->label('Descricao para a IA')
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ]),
                        Textarea::make('param_schema_json')
                            ->label('param_schema (JSON)')
                            ->visible(fn (Get $get): bool => (bool) $get('advanced_schema'))
                            ->rows(10)
                            ->helperText('Ex: {"properties":{"order_id":{"type":"string","location":"query","required":true,"description":"ID"}}}'),
                    ]),

                Section::make('Resposta para a IA')
                    ->columns(2)
                    ->schema([
                        Select::make('config.response_extraction.mode')
                            ->label('Modo de extracao')
                            ->options([
                                'jsonpath' => 'JSONPath (campo do JSON)',
                                'template' => 'Template (texto com {{ campo }})',
                            ])
                            ->default('jsonpath')
                            ->required(),
                        TextInput::make('config.response_extraction.max_length')
                            ->label('Tamanho maximo')
                            ->numeric()
                            ->minValue(1)
                            ->default(2000),
                        TextInput::make('config.response_extraction.expression')
                            ->label('Expressao')
                            ->columnSpanFull()
                            ->helperText('JSONPath: $.order.status ou items.0.name. Template: Pedido {{ order.id }}: {{ order.status }}.'),
                    ]),

                Section::make('Headers estaticos')
                    ->schema([
                        KeyValue::make('config.static_headers')
                            ->label('Headers fixos')
                            ->keyLabel('Header')
                            ->valueLabel('Valor')
                            ->helperText('Headers enviados em toda chamada (ex: Accept: application/json).'),
                    ]),
            ]);
    }
}
