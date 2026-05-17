<?php

declare(strict_types=1);

namespace App\Filament\Resources\Agents\Pages;

use App\Enums\AgentRunStatus;
use App\Filament\Resources\Agents\AgentResource;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ChatwootConnection;
use App\Services\AgentRuntime\AgentRuntimeClient;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;
use Throwable;

class EditAgent extends EditRecord
{
    protected static string $resource = AgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testRuntime')
                ->label('Testar runtime')
                ->icon('heroicon-o-play')
                ->schema([
                    Select::make('chatwoot_connection_id')
                        ->label('Conexao Chatwoot')
                        ->options(fn (): array => $this->chatwootConnectionOptions())
                        ->searchable()
                        ->required(),
                    Textarea::make('message')
                        ->label('Mensagem de teste')
                        ->default('preciso de ajuda no suporte')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    $this->runRuntimeTest($data);
                }),
            DeleteAction::make(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function chatwootConnectionOptions(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Agent) {
            return [];
        }

        return ChatwootConnection::query()
            ->where('workspace_id', $record->workspace_id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function runRuntimeTest(array $data): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Agent) {
            return;
        }

        $connection = ChatwootConnection::query()
            ->where('workspace_id', $record->workspace_id)
            ->findOrFail((int) $data['chatwoot_connection_id']);
        $message = is_string($data['message'] ?? null) ? $data['message'] : '';
        $conversationId = random_int(100000, 999999);
        $threadId = sprintf(
            'workspace:%d:account:%d:conversation:%d',
            $record->workspace_id,
            $connection->account_id,
            $conversationId,
        );
        $run = AgentRun::query()->create([
            'workspace_id' => $record->workspace_id,
            'agent_id' => $record->id,
            'chatwoot_connection_id' => $connection->id,
            'chatwoot_account_id' => $connection->account_id,
            'conversation_id' => $conversationId,
            'chatwoot_message_id' => 'admin-test-' . Str::uuid()->toString(),
            'thread_id' => $threadId,
            'status' => AgentRunStatus::Running,
            'input' => [
                'messages' => [
                    [
                        'id' => 'admin-test',
                        'content' => $message,
                    ],
                ],
            ],
            'started_at' => now(),
        ]);

        try {
            $response = app(AgentRuntimeClient::class)->run($run);

            $run->update([
                'status' => ($response['status'] ?? null) === 'waiting_human'
                    ? AgentRunStatus::WaitingHuman
                    : AgentRunStatus::Completed,
                'output' => $response,
                'finished_at' => now(),
            ]);

            Notification::make()
                ->success()
                ->title('Runtime testado com sucesso')
                ->body((string) data_get($response, 'response.content', 'Resposta sem conteudo textual.'))
                ->send();
        } catch (Throwable $exception) {
            $run->update([
                'status' => AgentRunStatus::Failed,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            Notification::make()
                ->danger()
                ->title('Falha ao testar runtime')
                ->body($exception->getMessage())
                ->send();
        }
    }
}
