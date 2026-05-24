<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Category;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('sku')
                                    ->label('SKU')
                                    ->maxLength(255),
                            ]),
                        Grid::make(2)
                            ->schema([
                                Select::make('category_id')
                                    ->label('Categoria')
                                    ->options(fn (): array => self::categoryOptions())
                                    ->searchable()
                                    ->preload(),
                                TextInput::make('price')
                                    ->label('Preco')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('R$'),
                            ]),
                        TextInput::make('description')
                            ->label('Descricao')
                            ->columnSpanFull(),
                        Toggle::make('active')
                            ->label('Ativo')
                            ->default(true),
                    ]),
                Section::make('Documentos')
                    ->description('PDFs e imagens associados a este produto. O agente pode envia-los ao cliente.')
                    ->schema([
                        Repeater::make('documents')
                            ->relationship('documents')
                            ->schema([
                                FileUpload::make('path')
                                    ->label('Arquivo')
                                    ->disk('s3')
                                    ->directory('documents')
                                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                                    ->maxSize(20480)
                                    ->required()
                                    ->storeFileName('original_filename')
                                    ->afterStateUpdated(function ($state, $set): void {
                                        if ($state) {
                                            $set('mime_type', $state->getMimeType());
                                            $set('size_bytes', $state->getSize());
                                        }
                                    }),
                                TextInput::make('original_filename')
                                    ->label('Nome do arquivo')
                                    ->maxLength(255),
                            ])
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => $state['original_filename'] ?? 'Documento')
                            ->addActionLabel('Adicionar documento'),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
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
