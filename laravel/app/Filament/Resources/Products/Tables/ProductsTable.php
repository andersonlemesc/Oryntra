<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
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
                TextColumn::make('category')
                    ->label('Categoria')
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
                    ->options(fn (): array => self::categoryOptions()),
                Filter::make('active')
                    ->label('Somente ativos')
                    ->query(fn (Builder $query): Builder => $query->where('active', true))
                    ->toggle(),
            ])
            ->headerActions([]);
    }

    /**
     * @return array<int, string>
     */
    private static function categoryOptions(): array
    {
        return Product::query()
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category', 'category')
            ->sort()
            ->all();
    }
}