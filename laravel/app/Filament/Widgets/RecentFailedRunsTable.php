<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AgentRunStatus;
use App\Filament\Resources\AgentRuns\AgentRunResource;
use App\Models\AgentRun;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentFailedRunsTable extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Falhas recentes (7 dias)';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getQuery())
            ->emptyStateHeading('Sem falhas nos ultimos 7 dias')
            ->paginated([10])
            ->columns([
                TextColumn::make('agent.name')->label('Agente')->searchable(),
                TextColumn::make('conversation_id')->label('Conversa')->searchable(),
                TextColumn::make('error_message')
                    ->label('Erro')
                    ->limit(60)
                    ->tooltip(fn (AgentRun $record): ?string => $record->error_message),
                TextColumn::make('finished_at')->label('Falhou')->dateTime()->since()->sortable(),
            ])
            ->defaultSort('finished_at', 'desc')
            ->recordActions([
                Action::make('view')
                    ->label('Abrir')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (AgentRun $record): string => AgentRunResource::getUrl('view', ['record' => $record])),
            ]);
    }

    /**
     * @return Builder<AgentRun>
     */
    protected function getQuery(): Builder
    {
        $tenantId = $this->tenantId();

        $query = AgentRun::query()
            ->where('status', AgentRunStatus::Failed->value)
            ->where('finished_at', '>=', now()->subDays(7));

        if ($tenantId !== null) {
            $query->where('workspace_id', $tenantId);
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query->limit(10);
    }

    private function tenantId(): ?int
    {
        $tenant = Filament::getTenant();

        return $tenant === null ? null : (int) $tenant->getKey();
    }
}
