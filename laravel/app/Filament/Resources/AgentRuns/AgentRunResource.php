<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentRuns;

use App\Filament\Resources\AgentRuns\Pages\ListAgentRuns;
use App\Filament\Resources\AgentRuns\Pages\ViewAgentRun;
use App\Filament\Resources\AgentRuns\Schemas\AgentRunInfolist;
use App\Filament\Resources\AgentRuns\Tables\AgentRunsTable;
use App\Models\AgentRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AgentRunResource extends Resource
{
    protected static ?string $model = AgentRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Agentes';

    protected static ?string $navigationLabel = 'Execuções';

    protected static ?string $modelLabel = 'execução';

    protected static ?string $pluralModelLabel = 'execuções';

    protected static ?string $recordTitleAttribute = 'thread_id';

    public static function table(Table $table): Table
    {
        return AgentRunsTable::configure($table);
    }

    /**
     * Exclude playground test runs from the Chatwoot execution log.
     *
     * @return Builder<AgentRun>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->fromChatwoot();
    }

    public static function infolist(Schema $schema): Schema
    {
        return AgentRunInfolist::configure($schema);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentRuns::route('/'),
            'view' => ViewAgentRun::route('/{record}'),
        ];
    }
}
