<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificacao')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone_number')
                            ->label('Telefone')
                            ->maxLength(40),
                        TextInput::make('identifier')
                            ->label('Identifier Chatwoot')
                            ->maxLength(120)
                            ->disabled()
                            ->dehydrated(false),
                    ]),
                Section::make('Endereco de entrega')
                    ->columns(3)
                    ->schema([
                        TextInput::make('address_postal_code')
                            ->label('CEP')
                            ->maxLength(20),
                        TextInput::make('address_street')
                            ->label('Rua / Avenida')
                            ->maxLength(255)
                            ->columnSpan(2),
                        TextInput::make('address_number')
                            ->label('Numero')
                            ->maxLength(40),
                        TextInput::make('address_complement')
                            ->label('Complemento')
                            ->maxLength(255),
                        TextInput::make('address_neighborhood')
                            ->label('Bairro')
                            ->maxLength(120),
                        TextInput::make('address_city')
                            ->label('Cidade')
                            ->maxLength(120),
                        TextInput::make('address_state')
                            ->label('Estado')
                            ->maxLength(80),
                        TextInput::make('address_country')
                            ->label('Pais')
                            ->maxLength(80),
                        TextInput::make('address_reference')
                            ->label('Ponto de referencia / observacoes')
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),
                Section::make('Lead')
                    ->columns(2)
                    ->schema([
                        Select::make('lead_status')
                            ->label('Status do lead')
                            ->options([
                                'new' => 'Novo',
                                'contacted' => 'Contatado',
                                'qualified' => 'Qualificado',
                                'won' => 'Convertido',
                                'lost' => 'Perdido',
                                'dormant' => 'Inativo',
                            ])
                            ->required()
                            ->native(false),
                        TextInput::make('lead_score')
                            ->label('Score (opcional)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100),
                    ]),
            ]);
    }
}
