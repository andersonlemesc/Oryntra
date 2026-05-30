<?php

declare(strict_types=1);

namespace App\Filament\Resources\McpServers;

use App\Enums\ExternalToolKind;
use App\Filament\Resources\McpServers\Pages\CreateMcpServer;
use App\Filament\Resources\McpServers\Pages\EditMcpServer;
use App\Filament\Resources\McpServers\Pages\ListMcpServers;
use App\Filament\Resources\McpServers\Schemas\McpServerForm;
use App\Filament\Resources\McpServers\Tables\McpServersTable;
use App\Models\ExternalTool;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class McpServerResource extends Resource
{
    protected static ?string $model = ExternalTool::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Agentes';

    protected static ?string $navigationLabel = 'Servidores MCP';

    protected static ?string $modelLabel = 'Servidor MCP';

    protected static ?string $pluralModelLabel = 'Servidores MCP';

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $slug = 'mcp-servers';

    public static function form(Schema $schema): Schema
    {
        return McpServerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return McpServersTable::configure($table);
    }

    /**
     * @return Builder<ExternalTool>
     */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        return parent::getEloquentQuery()
            ->where('kind', ExternalToolKind::Mcp)
            ->when($tenant !== null, fn (Builder $query): Builder => $query->where('workspace_id', $tenant->getKey()));
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMcpServers::route('/'),
            'create' => CreateMcpServer::route('/create'),
            'edit' => EditMcpServer::route('/{record}/edit'),
        ];
    }
}
