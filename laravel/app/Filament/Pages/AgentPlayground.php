<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AgentStatus;
use App\Enums\PlaygroundMessageRole;
use App\Enums\PlaygroundMessageStatus;
use App\Jobs\Playground\StreamPlaygroundRunJob;
use App\Models\Agent;
use App\Models\Contact;
use App\Models\PlaygroundConversation;
use App\Models\PlaygroundMessage;
use App\Models\Workspace;
use App\Services\Playground\PlaygroundRuntimeClient;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class AgentPlayground extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Agentes';

    protected static ?string $title = 'Playground';

    protected static ?string $slug = 'playground';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.pages.agent-playground';

    public ?int $agentId = null;

    public ?int $contactId = null;

    public ?int $conversationId = null;

    public string $draft = '';

    public static function getNavigationLabel(): string
    {
        return 'Playground';
    }

    public function mount(): void
    {
        $this->agentId = $this->agentOptions()->keys()->first();
        $this->conversationId = $this->conversations()->first()?->id;

        if ($this->conversationId !== null) {
            $this->hydrateSelectionFromConversation();
        }
    }

    /**
     * Workspace agents available to test, keyed by id.
     *
     * @return Collection<int, string>
     */
    public function agentOptions(): Collection
    {
        return Agent::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('status', AgentStatus::Active->value)
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    /**
     * Contacts of the workspace for an optional realistic context.
     *
     * @return Collection<int, string>
     */
    public function contactOptions(): Collection
    {
        return Contact::query()
            ->where('workspace_id', $this->workspaceId())
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'name', 'phone_number'])
            ->mapWithKeys(function (Contact $contact): array {
                $label = $contact->name ?? '';

                if ($label === '') {
                    $label = $contact->phone_number ?? '';
                }

                if ($label === '') {
                    $label = "Contato #{$contact->id}";
                }

                return [$contact->id => trim($label)];
            });
    }

    /**
     * Conversations of the current user in this workspace, newest first.
     *
     * @return Collection<int, PlaygroundConversation>
     */
    public function conversations(): Collection
    {
        return PlaygroundConversation::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('user_id', Auth::id())
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Messages of the active conversation, oldest first.
     *
     * @return Collection<int, PlaygroundMessage>
     */
    public function messages(): Collection
    {
        if ($this->conversationId === null) {
            return collect();
        }

        return PlaygroundMessage::query()
            ->where('playground_conversation_id', $this->conversationId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function startNewConversation(): void
    {
        $this->conversationId = null;
        $this->draft = '';
        $this->dispatch('playground-conversation-changed', conversationId: null);
    }

    public function selectConversation(int $conversationId): void
    {
        $conversation = $this->ownedConversation($conversationId);

        if ($conversation === null) {
            return;
        }

        $this->conversationId = $conversation->id;
        $this->hydrateSelectionFromConversation();
        $this->dispatch('playground-conversation-changed', conversationId: $conversation->id);
    }

    public function deleteConversation(int $conversationId): void
    {
        $conversation = $this->ownedConversation($conversationId);

        if ($conversation === null) {
            return;
        }

        $conversation->delete();

        if ($this->conversationId === $conversationId) {
            $this->conversationId = null;
            $this->dispatch('playground-conversation-changed', conversationId: null);
        }
    }

    public function sendMessage(PlaygroundRuntimeClient $runtime): void
    {
        $content = trim($this->draft);

        $this->validateForSend($content);

        $conversation = $this->conversationId !== null
            ? $this->ownedConversation($this->conversationId)
            : $this->createConversation($content);

        if ($conversation === null) {
            return;
        }

        $this->conversationId = $conversation->id;

        PlaygroundMessage::query()->create([
            'playground_conversation_id' => $conversation->id,
            'role' => PlaygroundMessageRole::User,
            'content' => $content,
        ]);

        $run = $runtime->createTurnRun($conversation, $content);

        $assistant = PlaygroundMessage::query()->create([
            'playground_conversation_id' => $conversation->id,
            'agent_run_id' => $run->id,
            'role' => PlaygroundMessageRole::Assistant,
            'status' => PlaygroundMessageStatus::Pending,
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        $this->draft = '';

        StreamPlaygroundRunJob::dispatch($assistant->id);

        $this->dispatch(
            'playground-message-pending',
            conversationId: $conversation->id,
            messageId: $assistant->id,
        );
    }

    public function getHeading(): string|Htmlable
    {
        return 'Playground';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Teste o agente como um chat. As tools executam de verdade.';
    }

    private function hydrateSelectionFromConversation(): void
    {
        $conversation = $this->conversationId !== null
            ? $this->ownedConversation($this->conversationId)
            : null;

        if ($conversation === null) {
            return;
        }

        $this->agentId = $conversation->agent_id;
        $this->contactId = $conversation->contact_id;
    }

    private function createConversation(string $content): ?PlaygroundConversation
    {
        $agentId = $this->resolvedAgentId();

        if ($agentId === null) {
            return null;
        }

        $conversation = new PlaygroundConversation([
            'workspace_id' => $this->workspaceId(),
            'agent_id' => $agentId,
            'contact_id' => $this->resolvedContactId(),
            'user_id' => Auth::id(),
            'title' => mb_strimwidth($content, 0, 60, '…'),
            'last_message_at' => now(),
        ]);
        $conversation->thread_id = 'pending';
        $conversation->save();

        $conversation->forceFill(['thread_id' => $conversation->buildThreadId()])->save();

        return $conversation;
    }

    private function validateForSend(string $content): void
    {
        if ($content === '') {
            throw ValidationException::withMessages(['draft' => 'Escreva uma mensagem.']);
        }

        if ($this->conversationId === null && $this->resolvedAgentId() === null) {
            throw ValidationException::withMessages(['agentId' => 'Selecione um agente.']);
        }
    }

    private function resolvedAgentId(): ?int
    {
        if ($this->agentId !== null && $this->agentOptions()->has($this->agentId)) {
            return $this->agentId;
        }

        return null;
    }

    private function resolvedContactId(): ?int
    {
        if ($this->contactId !== null && $this->contactOptions()->has($this->contactId)) {
            return $this->contactId;
        }

        return null;
    }

    private function ownedConversation(int $conversationId): ?PlaygroundConversation
    {
        return PlaygroundConversation::query()
            ->where('id', $conversationId)
            ->where('workspace_id', $this->workspaceId())
            ->where('user_id', Auth::id())
            ->first();
    }

    private function workspaceId(): int
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Workspace ? $tenant->id : 0;
    }
}
