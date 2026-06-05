<?php

declare(strict_types=1);

namespace App\Filament\Resources\ExternalTools;

use App\Filament\Resources\ExternalTools\Pages\CreateExternalTool;
use App\Filament\Resources\ExternalTools\Pages\EditExternalTool;
use App\Filament\Resources\ExternalTools\Pages\ListExternalTools;
use App\Filament\Resources\ExternalTools\Schemas\ExternalToolForm;
use App\Filament\Resources\ExternalTools\Tables\ExternalToolsTable;
use App\Models\ExternalTool;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ExternalToolResource extends Resource
{
    protected static ?string $model = ExternalTool::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Agentes';

    protected static ?string $navigationLabel = 'APIs externas';

    protected static ?string $modelLabel = 'API externa';

    protected static ?string $pluralModelLabel = 'APIs externas';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return ExternalToolForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExternalToolsTable::configure($table);
    }

    /**
     * @return Builder<ExternalTool>
     */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        return parent::getEloquentQuery()
            ->when($tenant !== null, fn (Builder $query): Builder => $query->where('workspace_id', $tenant->getKey()));
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExternalTools::route('/'),
            'create' => CreateExternalTool::route('/create'),
            'edit' => EditExternalTool::route('/{record}/edit'),
        ];
    }
}
