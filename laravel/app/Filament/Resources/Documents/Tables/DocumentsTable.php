<?php

declare(strict_types=1);

namespace App\Filament\Resources\Documents\Tables;

use App\Enums\DocumentCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                    ->formatStateUsing(fn (string $state): string => DocumentCategory::tryFrom($state)?->label() ?? $state)
                    ->color(fn (string $state): string => DocumentCategory::tryFrom($state)?->isSendable() ? 'success' : 'gray')
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
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->options(DocumentCategory::options()),
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
