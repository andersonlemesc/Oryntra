<?php

declare(strict_types=1);

namespace App\Filament\Resources\Documents\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Titulo')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label('Categoria')
                    ->badge()
                    ->sortable(),
                TextColumn::make('original_filename')
                    ->label('Arquivo')
                    ->limit(30)
                    ->tooltip(fn ($state): string => $state),
                TextColumn::make('mime_type')
                    ->label('Tipo')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('size_bytes')
                    ->label('Tamanho')
                    ->formatStateUsing(fn ($state): string => number_format($state / 1024, 1) . ' KB')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
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
