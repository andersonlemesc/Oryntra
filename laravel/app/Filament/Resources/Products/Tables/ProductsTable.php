<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Models\Category;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('category.name')
                    ->label('Categoria')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('tags')
                    ->label('Tags')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('documents_count')
                    ->label('Documentos')
                    ->counts('documents')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('price')
                    ->label('Preco')
                    ->money('BRL')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('active')
                    ->label('Ativo')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Sim' : 'Nao')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('name', 'asc')
            ->filters([
                SelectFilter::make('category')
                    ->label('Categoria')
                    ->attribute('category_id')
                    ->options(fn (): array => self::categoryOptions()),
                Filter::make('active')
                    ->label('Somente ativos')
                    ->query(fn (Builder $query): Builder => $query->where('active', true))
                    ->toggle(),
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

    /**
     * @return array<string, string>
     */
    private static function categoryOptions(): array
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return [];
        }

        return Category::query()
            ->where('workspace_id', $tenant->getKey())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
