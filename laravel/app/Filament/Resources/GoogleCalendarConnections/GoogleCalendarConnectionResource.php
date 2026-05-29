<?php

declare(strict_types=1);

namespace App\Filament\Resources\GoogleCalendarConnections;

use App\Filament\Resources\GoogleCalendarConnections\Pages\EditGoogleCalendarConnection;
use App\Filament\Resources\GoogleCalendarConnections\Pages\ListGoogleCalendarConnections;
use App\Filament\Resources\GoogleCalendarConnections\Schemas\GoogleCalendarConnectionForm;
use App\Filament\Resources\GoogleCalendarConnections\Tables\GoogleCalendarConnectionsTable;
use App\Models\GoogleCalendarConnection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class GoogleCalendarConnectionResource extends Resource
{
    protected static ?string $model = GoogleCalendarConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Integrações';

    protected static ?string $navigationLabel = 'Google Calendar';

    protected static ?string $modelLabel = 'conexão Google Calendar';

    protected static ?string $pluralModelLabel = 'conexões Google Calendar';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return GoogleCalendarConnectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GoogleCalendarConnectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoogleCalendarConnections::route('/'),
            'edit' => EditGoogleCalendarConnection::route('/{record}/edit'),
        ];
    }
}
