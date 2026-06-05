<?php

declare(strict_types=1);

namespace App\Filament\Resources\Agents;

use App\Filament\Resources\Agents\Pages\CreateAgent;
use App\Filament\Resources\Agents\Pages\EditAgent;
use App\Filament\Resources\Agents\Pages\ListAgents;
use App\Filament\Resources\Agents\RelationManagers\ChatwootBindingsRelationManager;
use App\Filament\Resources\Agents\RelationManagers\KnowledgeRelationManager;
use App\Filament\Resources\Agents\RelationManagers\ProductsRelationManager;
use App\Filament\Resources\Agents\RelationManagers\SpecialistsRelationManager;
use App\Filament\Resources\Agents\Schemas\AgentForm;
use App\Filament\Resources\Agents\Tables\AgentsTable;
use App\Models\Agent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AgentResource extends Resource
{
    protected static ?string $model = Agent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Agentes';

    protected static ?string $navigationLabel = 'Agentes';

    protected static ?string $modelLabel = 'agente';

    protected static ?string $pluralModelLabel = 'agentes';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AgentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChatwootBindingsRelationManager::class,
            SpecialistsRelationManager::class,
            ProductsRelationManager::class,
            KnowledgeRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgents::route('/'),
            'create' => CreateAgent::route('/create'),
            'edit' => EditAgent::route('/{record}/edit'),
        ];
    }
}
