<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('workspace_id')
                    ->default(fn (): ?int => Filament::getTenant()?->getKey()),
                Section::make('Dados da categoria')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->live()
                            ->afterStateUpdated(fn (string $state, callable $set): mixed => $set('slug', Str::slug($state))),
                        TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: Category::class,
                                column: 'slug',
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('workspace_id', Filament::getTenant()?->getKey()),
                            ),
                        TextInput::make('description')
                            ->label('Descricao')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
