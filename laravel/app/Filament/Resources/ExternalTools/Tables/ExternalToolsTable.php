<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExternalTools\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExternalToolsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Rotulo')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->badge()
                    ->color('info')
                    ->searchable(),
                TextColumn::make('config.http_method')
                    ->label('Metodo')
                    ->badge(),
                TextColumn::make('config.base_url')
                    ->label('Base URL')
                    ->limit(40)
                    ->tooltip(fn ($state): ?string => $state),
                IconColumn::make('enabled')
                    ->label('Ativa')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criada em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
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
