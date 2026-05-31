<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentDocuments;

use App\Filament\Resources\AgentDocuments\Pages\CreateAgentDocument;
use App\Filament\Resources\AgentDocuments\Pages\ListAgentDocuments;
use App\Filament\Resources\AgentDocuments\Schemas\AgentDocumentForm;
use App\Filament\Resources\AgentDocuments\Tables\AgentDocumentsTable;
use App\Models\AgentDocument;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AgentDocumentResource extends Resource
{
    protected static ?string $model = AgentDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Agentes';

    protected static ?string $navigationLabel = 'Base de Conhecimento';

    protected static ?string $modelLabel = 'documento de conhecimento';

    protected static ?string $pluralModelLabel = 'base de conhecimento';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AgentDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgentDocumentsTable::configure($table);
    }

    /**
     * @return Builder<AgentDocument>
     */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        /** @var Builder<AgentDocument> $query */
        $query = parent::getEloquentQuery();

        if ($tenant !== null) {
            $query->where('workspace_id', $tenant->getKey());
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentDocuments::route('/'),
            'create' => CreateAgentDocument::route('/create'),
        ];
    }
}
