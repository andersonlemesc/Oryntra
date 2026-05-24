<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('workspace_id')
                    ->default(fn (): ?int => Filament::getTenant()?->getKey()),
                Section::make('Dados do produto')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Nome')
                                    ->required(),
                                TextInput::make('sku')
                                    ->label('SKU'),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('category')
                                    ->label('Categoria'),
                                TextInput::make('price')
                                    ->label('Preço')
                                    ->numeric()
                                    ->prefix('R$'),
                            ]),
                        TextInput::make('description')
                            ->label('Descrição')
                            ->columnSpanFull(),
                        Toggle::make('active')
                            ->label('Ativo')
                            ->default(true),
                    ]),
            ]);
    }
}