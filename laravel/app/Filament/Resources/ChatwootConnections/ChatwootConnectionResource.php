<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChatwootConnections;

use App\Filament\Resources\ChatwootConnections\Pages\CreateChatwootConnection;
use App\Filament\Resources\ChatwootConnections\Pages\EditChatwootConnection;
use App\Filament\Resources\ChatwootConnections\Pages\ListChatwootConnections;
use App\Filament\Resources\ChatwootConnections\Schemas\ChatwootConnectionForm;
use App\Filament\Resources\ChatwootConnections\Tables\ChatwootConnectionsTable;
use App\Models\ChatwootConnection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ChatwootConnectionResource extends Resource
{
    protected static ?string $model = ChatwootConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Chatwoot';

    protected static ?string $navigationLabel = 'Conexões';

    protected static ?string $modelLabel = 'conexão Chatwoot';

    protected static ?string $pluralModelLabel = 'conexões Chatwoot';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ChatwootConnectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChatwootConnectionsTable::configure($table);
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
            'index' => ListChatwootConnections::route('/'),
            'create' => CreateChatwootConnection::route('/create'),
            'edit' => EditChatwootConnection::route('/{record}/edit'),
        ];
    }
}
