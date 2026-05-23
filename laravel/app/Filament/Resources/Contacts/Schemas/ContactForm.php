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
