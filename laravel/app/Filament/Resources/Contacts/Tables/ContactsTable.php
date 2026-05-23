<?php

declare(strict_types=1);

namespace App\Filament\Resources\Contacts\Tables;

use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable()
                    ->default('—'),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('phone_number')
                    ->label('Telefone')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('lead_status')
                    ->label('Status')
                    ->badge()
                    ->colors([
                        'gray' => 'new',
                        'info' => 'contacted',
                        'warning' => 'qualified',
                        'success' => 'won',
                        'danger' => 'lost',
                        'zinc' => 'dormant',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'new' => 'Novo',
                        'contacted' => 'Contatado',
                        'qualified' => 'Qualificado',
                        'won' => 'Convertido',
                        'lost' => 'Perdido',
                        'dormant' => 'Inativo',
                        default => $state,
                    }),
                TextColumn::make('last_message_at')
                    ->label('Ultima mensagem')
                    ->since()
                    ->sortable(),
                TextColumn::make('workspace.name')
                    ->label('Workspace')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('chatwootConnection.name')
                    ->label('Conexao Chatwoot')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('synced_at')
                    ->label('Sincronizado em')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                SelectFilter::make('lead_status')
                    ->label('Status')
                    ->multiple()
                    ->options([
                        'new' => 'Novo',
                        'contacted' => 'Contatado',
                        'qualified' => 'Qualificado',
                        'won' => 'Convertido',
                        'lost' => 'Perdido',
                        'dormant' => 'Inativo',
                    ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::leadStatusBulkAction('qualified', 'Marcar como qualificado', 'heroicon-o-star'),
                    self::leadStatusBulkAction('won', 'Marcar como convertido', 'heroicon-o-trophy'),
                    self::leadStatusBulkAction('lost', 'Marcar como perdido', 'heroicon-o-x-circle'),
                    self::leadStatusBulkAction('dormant', 'Marcar como inativo', 'heroicon-o-moon'),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    private static function leadStatusBulkAction(string $status, string $label, string $icon): BulkAction
    {
        return BulkAction::make("lead_status_{$status}")
            ->label($label)
            ->icon($icon)
            ->requiresConfirmation()
            ->action(function (Collection $records) use ($status): void {
                $records->each(fn ($record) => $record->forceFill(['lead_status' => $status])->save());
            });
    }
}
