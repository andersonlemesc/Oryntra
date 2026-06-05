<?php

declare(strict_types=1);

namespace App\Filament\Resources\Documents\Schemas;

use App\Enums\DocumentCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('workspace_id')
                    ->default(fn (): ?int => Filament::getTenant()?->getKey()),
                Section::make('Dados do documento')
                    ->schema([
                        Select::make('category')
                            ->label('Categoria')
                            ->options(DocumentCategory::sendableOptions())
                            ->required()
                            ->default(DocumentCategory::General->value)
                            ->helperText('Midias sao enviadas ao cliente pela IA. Para documentos que a IA apenas consulta, use a Base de Conhecimento.'),
                        TextInput::make('title')
                            ->label('Titulo')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('description')
                            ->label('Descricao')
                            ->maxLength(1000),
                    ]),
                Section::make('Arquivo')
                    ->schema([
                        FileUpload::make('path')
                            ->label('Arquivo')
                            ->disk('s3')
                            ->directory('documents')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(20480)
                            ->required()
                            ->storeFileNamesIn('original_filename'),
                    ]),
            ]);
    }
}
