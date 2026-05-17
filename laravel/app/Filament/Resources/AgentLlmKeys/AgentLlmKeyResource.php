<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentLlmKeys;

use App\Filament\Resources\AgentLlmKeys\Pages\CreateAgentLlmKey;
use App\Filament\Resources\AgentLlmKeys\Pages\EditAgentLlmKey;
use App\Filament\Resources\AgentLlmKeys\Pages\ListAgentLlmKeys;
use App\Filament\Resources\AgentLlmKeys\Schemas\AgentLlmKeyForm;
use App\Filament\Resources\AgentLlmKeys\Tables\AgentLlmKeysTable;
use App\Models\AgentLlmKey;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AgentLlmKeyResource extends Resource
{
    protected static ?string $model = AgentLlmKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Agentes';

    protected static ?string $navigationLabel = 'Chaves LLM';

    protected static ?string $modelLabel = 'chave LLM';

    protected static ?string $pluralModelLabel = 'chaves LLM';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AgentLlmKeyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgentLlmKeysTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentLlmKeys::route('/'),
            'create' => CreateAgentLlmKey::route('/create'),
            'edit' => EditAgentLlmKey::route('/{record}/edit'),
        ];
    }
}
