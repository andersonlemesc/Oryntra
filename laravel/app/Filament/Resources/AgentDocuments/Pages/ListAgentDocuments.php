<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentDocuments\Pages;

use App\Enums\AgentDocumentStatus;
use App\Enums\AgentLlmProvider;
use App\Filament\Resources\AgentDocuments\AgentDocumentResource;
use App\Jobs\Rag\IndexKnowledgeDocumentJob;
use App\Models\AgentDocument;
use App\Models\AgentLlmKey;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;

class ListAgentDocuments extends ListRecords
{
    protected static string $resource = AgentDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->configureEmbeddingsAction(),
            CreateAction::make(),
        ];
    }

    private function configureEmbeddingsAction(): Action
    {
        return Action::make('configureEmbeddings')
            ->label('Configurar embeddings')
            ->icon('heroicon-o-cpu-chip')
            ->fillForm(fn (): array => $this->currentEmbeddingConfig())
            ->schema([
                Select::make('embedding_llm_key_id')
                    ->label('Chave LLM de embedding')
                    ->options(fn (): array => $this->workspaceKeyOptions())
                    ->required()
                    ->searchable()
                    ->live(),
                TextInput::make('embedding_model')
                    ->label('Modelo de embedding')
                    ->placeholder('ex.: text-embedding-3-small')
                    ->datalist(fn (Get $get): array => $this->embeddingModelSuggestions($get('embedding_llm_key_id')))
                    ->helperText('Modelos de embedding conhecidos do provedor. Pode digitar outro manualmente.')
                    ->required()
                    ->maxLength(255),
            ])
            ->requiresConfirmation()
            ->modalHeading('Configurar modelo de embedding')
            ->modalDescription(function (): string {
                $count = $this->workspaceDocumentCount();

                return "Trocar o modelo de embedding REINDEXA todos os {$count} documento(s) desta base. "
                    . 'Vetores de modelos diferentes nao sao compativeis, entao a base inteira sera reprocessada — '
                    . 'isso pode consumir creditos do seu provedor BYOK proporcionalmente ao volume. '
                    . 'Confirme apenas se entende o custo.';
            })
            ->modalSubmitActionLabel('Salvar e reindexar')
            ->action(function (array $data): void {
                $this->applyEmbeddingConfig(
                    (int) $data['embedding_llm_key_id'],
                    (string) $data['embedding_model'],
                );
            });
    }

    /**
     * @return array{embedding_llm_key_id: int|null, embedding_model: string|null}
     */
    private function currentEmbeddingConfig(): array
    {
        $workspace = Filament::getTenant();

        return [
            'embedding_llm_key_id' => $workspace instanceof Workspace ? $workspace->embedding_llm_key_id : null,
            'embedding_model' => $workspace instanceof Workspace
                ? (is_string($workspace->getAttribute('embedding_model')) ? $workspace->getAttribute('embedding_model') : null)
                : null,
        ];
    }

    private function applyEmbeddingConfig(int $keyId, string $model): void
    {
        $workspace = Filament::getTenant();

        if (! $workspace instanceof Workspace) {
            return;
        }

        $changed = $workspace->embedding_llm_key_id !== $keyId
            || $workspace->getAttribute('embedding_model') !== $model;

        $workspace->update([
            'embedding_llm_key_id' => $keyId,
            'embedding_model' => $model,
        ]);

        if (! $changed) {
            return;
        }

        DB::transaction(function () use ($workspace): void {
            AgentDocument::query()
                ->where('workspace_id', $workspace->getKey())
                ->update(['index_status' => AgentDocumentStatus::Pending->value]);
        });

        AgentDocument::query()
            ->where('workspace_id', $workspace->getKey())
            ->pluck('id')
            ->each(fn (int $id) => IndexKnowledgeDocumentJob::dispatch($id));
    }

    /**
     * @return array<int, string>
     */
    private function workspaceKeyOptions(): array
    {
        $workspace = Filament::getTenant();

        if (! $workspace instanceof Workspace) {
            return [];
        }

        return AgentLlmKey::query()
            ->where('workspace_id', $workspace->getKey())
            ->get()
            ->mapWithKeys(fn (AgentLlmKey $key): array => [$key->getKey() => $key->name])
            ->all();
    }

    /**
     * Known embedding models for the provider of the selected key (datalist
     * suggestions; free typing still allowed).
     *
     * @return array<int, string>
     */
    private function embeddingModelSuggestions(mixed $keyId): array
    {
        if (blank($keyId)) {
            return [];
        }

        $key = AgentLlmKey::query()->find($keyId);

        if (! $key instanceof AgentLlmKey) {
            return [];
        }

        $provider = $key->getAttribute('provider');
        $provider = $provider instanceof AgentLlmProvider ? $provider->value : (string) $provider;

        return match ($provider) {
            'openai', 'local' => ['text-embedding-3-small', 'text-embedding-3-large', 'text-embedding-ada-002'],
            'gemini' => ['gemini-embedding-001', 'text-embedding-004'],
            default => [],
        };
    }

    private function workspaceDocumentCount(): int
    {
        $workspace = Filament::getTenant();

        if (! $workspace instanceof Workspace) {
            return 0;
        }

        return AgentDocument::query()
            ->where('workspace_id', $workspace->getKey())
            ->count();
    }
}
