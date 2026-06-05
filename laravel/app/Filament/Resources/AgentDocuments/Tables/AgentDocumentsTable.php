<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentDocuments\Tables;

use App\Enums\AgentDocumentStatus;
use App\Jobs\Rag\IndexKnowledgeDocumentJob;
use App\Models\AgentDocument;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class AgentDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('mime_type')
                    ->label('Tipo')
                    ->toggleable(),
                TextColumn::make('index_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (AgentDocumentStatus $state): string => $state->label())
                    ->color(fn (AgentDocumentStatus $state): string => $state->color()),
                TextColumn::make('chunks_count')
                    ->label('Trechos')
                    ->sortable(),
                TextColumn::make('embedding_model')
                    ->label('Modelo')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('indexed_at')
                    ->label('Indexado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('index_status')
                    ->label('Status')
                    ->options(fn (): array => collect(AgentDocumentStatus::cases())
                        ->mapWithKeys(fn (AgentDocumentStatus $case): array => [$case->value => $case->label()])
                        ->all()),
            ])
            ->recordActions([
                Action::make('reindex')
                    ->label('Reindexar')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => self::userCanManageCurrentWorkspace())
                    ->requiresConfirmation()
                    ->modalDescription('Reprocessa o documento e regenera os embeddings. Pode consumir creditos do seu provedor.')
                    ->action(function (AgentDocument $record): void {
                        $record->update(['index_status' => AgentDocumentStatus::Pending]);
                        IndexKnowledgeDocumentJob::dispatch($record->id);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function userCanManageCurrentWorkspace(): bool
    {
        $user = Auth::user();
        $tenant = Filament::getTenant();

        return $user instanceof User
            && $tenant instanceof Workspace
            && $user->canManageWorkspace($tenant);
    }
}
