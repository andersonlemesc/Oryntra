<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Schemas;

use App\Models\Contact;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class ContactInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('contact')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Resumo')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Section::make('Identificacao')
                                    ->columns(2)
                                    ->schema([
                                        TextEntry::make('name')->label('Nome')->placeholder('—'),
                                        TextEntry::make('email')->label('Email')->placeholder('—'),
                                        TextEntry::make('phone_number')->label('Telefone')->placeholder('—'),
                                        TextEntry::make('identifier')->label('Identifier Chatwoot')->placeholder('—'),
                                        TextEntry::make('chatwoot_contact_id')->label('ID no Chatwoot'),
                                        TextEntry::make('chatwootConnection.name')->label('Conexao Chatwoot'),
                                    ]),
                                Section::make('Lead')
                                    ->columns(2)
                                    ->schema([
                                        TextEntry::make('lead_status')
                                            ->label('Status')
                                            ->badge()
                                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                                'new' => 'Novo',
                                                'contacted' => 'Contatado',
                                                'qualified' => 'Qualificado',
                                                'won' => 'Convertido',
                                                'lost' => 'Perdido',
                                                'dormant' => 'Inativo',
                                                default => $state,
                                            }),
                                        TextEntry::make('lead_score')->label('Score')->placeholder('—'),
                                        TextEntry::make('first_seen_at')->label('Primeira visita')->dateTime(),
                                        TextEntry::make('last_seen_at')->label('Ultima visita')->dateTime(),
                                        TextEntry::make('last_message_at')->label('Ultima mensagem')->dateTime()->placeholder('—'),
                                        TextEntry::make('synced_at')->label('Ultima sync Chatwoot')->dateTime()->placeholder('—'),
                                    ]),
                                TextEntry::make('deleted_at')
                                    ->label('Removido em')
                                    ->dateTime()
                                    ->visible(fn (Contact $record): bool => $record->trashed()),
                            ]),
                        Tab::make('Chatwoot raw')
                            ->icon('heroicon-o-document-text')
                            ->schema([
                                KeyValueEntry::make('chatwoot_custom_attributes')
                                    ->label('Custom attributes')
                                    ->placeholder('—'),
                                KeyValueEntry::make('additional_attributes')
                                    ->label('Additional attributes')
                                    ->placeholder('—'),
                            ]),
                    ]),
            ]);
    }
}
