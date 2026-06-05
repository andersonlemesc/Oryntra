<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\ChatwootConversationState;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Conversations a human agent has taken over: the bot is paused on them until
 * the conversation is resolved in Chatwoot. Backed by chatwoot_conversation_states
 * (human_takeover_at), not by agent run status.
 */
class HumanTakeoverConversationsTable extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Conversas assumidas por humano';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getQuery())
            ->emptyStateHeading('Nenhuma conversa assumida por humano')
            ->columns([
                TextColumn::make('conversation_id')
                    ->label('Conversa')
                    ->searchable(),
                TextColumn::make('chatwootConnection.name')
                    ->label('Bot / conexao')
                    ->placeholder('—'),
                TextColumn::make('human_takeover_at')
                    ->label('Assumida')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('human_takeover_at', 'desc')
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->recordActions([
                Action::make('openInChatwoot')
                    ->label('Abrir no Chatwoot')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ChatwootConversationState $record): ?string => $this->chatwootUrl($record), shouldOpenInNewTab: true)
                    ->visible(fn (ChatwootConversationState $record): bool => $this->chatwootUrl($record) !== null),
            ]);
    }

    /**
     * @return Builder<ChatwootConversationState>
     */
    protected function getQuery(): Builder
    {
        $tenantId = $this->tenantId();

        $query = ChatwootConversationState::query()
            ->with('chatwootConnection')
            ->whereNotNull('human_takeover_at');

        if ($tenantId !== null) {
            $query->where('workspace_id', $tenantId);
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function chatwootUrl(ChatwootConversationState $record): ?string
    {
        $connection = $record->chatwootConnection;

        if ($connection === null) {
            return null;
        }

        $baseUrl = rtrim((string) $connection->base_url, '/');
        $accountId = (int) $connection->account_id;

        if ($baseUrl === '' || $accountId <= 0) {
            return null;
        }

        return "{$baseUrl}/app/accounts/{$accountId}/conversations/{$record->conversation_id}";
    }

    private function tenantId(): ?int
    {
        $tenant = Filament::getTenant();

        return $tenant === null ? null : (int) $tenant->getKey();
    }
}
