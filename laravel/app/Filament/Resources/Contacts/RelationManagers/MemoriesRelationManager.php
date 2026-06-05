<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\RelationManagers;

use App\Enums\ContactMemorySource;
use App\Enums\ContactMemoryType;
use App\Models\Contact;
use App\Models\ContactMemory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MemoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'memories';

    protected static ?string $title = 'Memorias';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('Tipo')
                    ->options(ContactMemoryType::options())
                    ->default(ContactMemoryType::Fact->value)
                    ->required()
                    ->native(false),
                Textarea::make('content')
                    ->label('Conteudo')
                    ->rows(3)
                    ->required()
                    ->maxLength(2000)
                    ->helperText('Frase curta descrevendo o fato. Ex: "Cliente prefere bike eletrica urbana".')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('content')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (ContactMemoryType|string $state): string => $state instanceof ContactMemoryType
                        ? $state->label()
                        : ContactMemoryType::from($state)->label()),
                TextColumn::make('content')
                    ->label('Conteudo')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('source')
                    ->label('Origem')
                    ->badge()
                    ->colors([
                        'gray' => 'manual',
                        'info' => 'agent_extracted',
                        'success' => 'tool',
                        'warning' => 'chatwoot_attribute',
                    ])
                    ->formatStateUsing(fn (ContactMemorySource|string $state): string => $state instanceof ContactMemorySource
                        ? $state->label()
                        : ContactMemorySource::from($state)->label()),
                TextColumn::make('confidence')
                    ->label('Confianca')
                    ->numeric(2)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->multiple()
                    ->options(ContactMemoryType::options()),
                SelectFilter::make('source')
                    ->label('Origem')
                    ->multiple()
                    ->options(ContactMemorySource::options()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar memoria')
                    ->mutateDataUsing(function (array $data): array {
                        /** @var Contact $contact */
                        $contact = $this->getOwnerRecord();
                        $data['workspace_id'] = $contact->workspace_id;
                        $data['source'] = ContactMemorySource::Manual->value;
                        $data['author_user_id'] = auth()->id();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (ContactMemory $record): bool => $record->source === ContactMemorySource::Manual),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
