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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Catalogo do agente';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->description('Produtos que este agente enxerga. Vazio = o agente usa todo o catalogo global do workspace; ao vincular ao menos um, ele passa a ver apenas os vinculados (mais os produtos sem nenhum vinculo).')
            ->columns([
                TextColumn::make('name')->label('Produto')->searchable(),
                TextColumn::make('category.name')->label('Categoria')->toggleable(),
                TextColumn::make('price')->label('Preco')->money('BRL')->sortable(),
                IconColumn::make('active')->label('Ativo')->boolean(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Vincular produto')
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
