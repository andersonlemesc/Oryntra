<?php

declare(strict_types=1);

namespace App\Filament\Resources\Agents\RelationManagers;

use App\Models\Agent;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class KnowledgeRelationManager extends RelationManager
{
    protected static string $relationship = 'knowledgeDocuments';

    protected static ?string $title = 'Base de conhecimento do agente';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->description('Documentos RAG que este agente pode pesquisar. Vazio = o agente usa toda a base global do workspace; ao vincular ao menos um, ele passa a ver apenas os vinculados (mais os documentos sem nenhum vinculo).')
            ->columns([
                TextColumn::make('name')->label('Documento')->searchable(),
                TextColumn::make('index_status')->label('Indexacao')->badge(),
                TextColumn::make('chunks_count')->label('Chunks')->numeric(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Vincular documento')
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query->where('workspace_id', $this->ownerWorkspaceId()))
                    ->preloadRecordSelect(),
            ])
            ->recordActions([
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }

    private function ownerWorkspaceId(): int
    {
        /** @var Agent $agent */
        $agent = $this->getOwnerRecord();

        return (int) $agent->workspace_id;
    }
}
