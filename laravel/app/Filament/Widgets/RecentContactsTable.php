<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentContactsTable extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Contatos recentes';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getQuery())
            ->emptyStateHeading('Nenhum contato registrado')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->default('—')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('phone_number')
                    ->label('Telefone')
                    ->placeholder('—')
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
            ])
            ->defaultSort('last_message_at', 'desc')
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->recordActions([
                Action::make('view')
                    ->label('Abrir')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Contact $record): string => ContactResource::getUrl('view', ['record' => $record])),
            ]);
    }

    /**
     * @return Builder<Contact>
     */
    protected function getQuery(): Builder
    {
        $tenantId = $this->tenantId();

        $query = Contact::query()->whereNotNull('last_message_at');

        if ($tenantId !== null) {
            $query->where('workspace_id', $tenantId);
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function tenantId(): ?int
    {
        $tenant = Filament::getTenant();

        return $tenant === null ? null : (int) $tenant->getKey();
    }
}
