<?php

declare(strict_types=1);

namespace App\Filament\Resources\GoogleCalendarConnections\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GoogleCalendarConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Apelido')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('google_email')
                    ->label('Conta Google')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('default_calendar_id')
                    ->label('Calendário padrão')
                    ->limit(40)
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Ativa')
                    ->boolean(),
                TextColumn::make('expires_at')
                    ->label('Token expira')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_used_at')
                    ->label('Último uso')
                    ->dateTime()
                    ->since()
                    ->placeholder('Nunca')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_error')
                    ->label('Erro')
                    ->limit(48)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Conectada em')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
