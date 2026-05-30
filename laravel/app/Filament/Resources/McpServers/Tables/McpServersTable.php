<?php

declare(strict_types=1);

namespace App\Filament\Resources\McpServers\Tables;

use App\Filament\Resources\McpServers\Support\McpToolsListContent;
use App\Models\ExternalTool;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class McpServersTable
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
                TextColumn::make('config.base_url')
                    ->label('URL')
                    ->limit(50)
                    ->tooltip(fn ($state): ?string => $state),
                TextColumn::make('config.auth_type')
                    ->label('Auth')
                    ->badge(),
                IconColumn::make('enabled')
                    ->label('Ativo')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('listTools')
                    ->label('Ver tools')
                    ->icon('heroicon-o-list-bullet')
                    ->color('info')
                    ->modalHeading(fn (ExternalTool $record): string => $record->label)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(fn (ExternalTool $record): HtmlString => new HtmlString(McpToolsListContent::buildHtml($record))),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
