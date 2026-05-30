@php
    use App\Enums\PlaygroundMessageRole;
    use App\Enums\PlaygroundMessageStatus;

    $conversations = $this->conversations();
    $messages = $this->messages();
    $agentOptions = $this->agentOptions();
    $contactOptions = $this->contactOptions();
@endphp

<x-filament-panels::page>
    @vite('resources/js/echo.js')

    <div
        class="grid h-[72vh] grid-cols-1 gap-4 md:grid-cols-[16rem_minmax(0,1fr)]"
        x-data="playgroundChat(@js($conversationId))"
    >
        {{-- Sidebar: conversations --}}
        <aside class="flex min-h-0 flex-col gap-3 rounded-xl bg-white p-3 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <x-filament::button wire:click="startNewConversation" icon="heroicon-o-plus" size="sm" class="w-full">
                Nova conversa
            </x-filament::button>

            <div class="flex-1 space-y-1 overflow-y-auto">
                @forelse ($conversations as $conversation)
                    <div
                        wire:key="conv-{{ $conversation->id }}"
                        @class([
                            'group flex items-center gap-2 rounded-lg px-2 py-2 text-sm cursor-pointer hover:bg-gray-100 dark:hover:bg-white/5',
                            'bg-primary-50 dark:bg-primary-500/10' => $conversation->id === $conversationId,
                        ])
                        wire:click="selectConversation({{ $conversation->id }})"
                    >
                        <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="h-4 w-4 shrink-0 text-gray-400" />
                        <span class="flex-1 truncate">{{ $conversation->title ?: 'Sem título' }}</span>
                        <button
                            type="button"
                            wire:click.stop="deleteConversation({{ $conversation->id }})"
                            wire:confirm="Apagar esta conversa?"
                            class="hidden text-gray-400 hover:text-danger-500 group-hover:block"
                        >
                            <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                        </button>
                    </div>
                @empty
                    <p class="px-2 py-4 text-center text-sm text-gray-400">Nenhuma conversa ainda.</p>
                @endforelse
            </div>
        </aside>

        {{-- Chat column --}}
        <section class="flex min-h-0 flex-col rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            {{-- Header: agent + contact --}}
            <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 p-3 dark:border-white/10">
                <label class="flex items-center gap-2 text-sm">
                    <span class="text-gray-500">Agente</span>
                    <select
                        wire:model="agentId"
                        @disabled($conversationId !== null)
                        class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800"
                    >
                        @foreach ($agentOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex items-center gap-2 text-sm">
                    <span class="text-gray-500">Contato</span>
                    <select
                        wire:model="contactId"
                        @disabled($conversationId !== null)
                        class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800"
                    >
                        <option value="">— nenhum —</option>
                        @foreach ($contactOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            {{-- Transcript --}}
            <div x-ref="transcript" class="flex-1 space-y-4 overflow-y-auto p-4">
                @forelse ($messages as $message)
                    @php
                        $isAssistant = $message->role === PlaygroundMessageRole::Assistant;
                        $isLive = $isAssistant && in_array($message->status, [PlaygroundMessageStatus::Pending, PlaygroundMessageStatus::Streaming], true);
                    @endphp

                    @continue($isLive)

                    @if ($message->role === PlaygroundMessageRole::User)
                        <div wire:key="msg-{{ $message->id }}" class="flex justify-end">
                            <div class="max-w-[80%] rounded-2xl rounded-br-sm bg-primary-600 px-4 py-2 text-sm text-white whitespace-pre-wrap">{{ $message->content }}</div>
                        </div>
                    @else
                        <div wire:key="msg-{{ $message->id }}" class="flex flex-col gap-1">
                            <div @class([
                                'max-w-[80%] rounded-2xl rounded-bl-sm px-4 py-2 text-sm whitespace-pre-wrap',
                                'bg-gray-100 text-gray-900 dark:bg-white/5 dark:text-gray-100' => $message->status !== PlaygroundMessageStatus::Failed,
                                'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' => $message->status === PlaygroundMessageStatus::Failed,
                            ])>
                                {{ $message->content ?: ($message->error_message ?: '—') }}
                            </div>

                            @if (filled($message->trace) || $message->specialist_id !== null)
                                <x-playground.debug-panel :message="$message" />
                            @endif
                        </div>
                    @endif
                @empty
                    <div class="flex h-full items-center justify-center text-center text-sm text-gray-400">
                        Escreva uma mensagem para testar o agente.
                    </div>
                @endforelse

                {{-- Live streaming assistant bubble (Alpine-driven) --}}
                <div x-show="live.active" x-cloak class="flex flex-col gap-1">
                    <div class="max-w-[80%] rounded-2xl rounded-bl-sm bg-gray-100 px-4 py-2 text-sm whitespace-pre-wrap dark:bg-white/5 dark:text-gray-100">
                        <span x-text="live.text"></span><span x-show="!live.text" class="text-gray-400">digitando…</span>
                    </div>

                    <template x-if="live.debug.length">
                        <details class="max-w-[80%] rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-white/5" open>
                            <summary class="cursor-pointer font-medium text-gray-500">Debug (ao vivo)</summary>
                            <ul class="mt-2 space-y-1">
                                <template x-for="(step, i) in live.debug" :key="i">
                                    <li class="font-mono text-[11px] text-gray-600 dark:text-gray-300">
                                        <span class="font-semibold" x-text="step.label"></span>
                                        <span x-text="step.detail"></span>
                                    </li>
                                </template>
                            </ul>
                        </details>
                    </template>
                </div>
            </div>

            {{-- Composer --}}
            <form wire:submit="sendMessage" class="border-t border-gray-100 p-3 dark:border-white/10">
                <div class="flex items-end gap-2">
                    <textarea
                        wire:model="draft"
                        x-on:keydown.enter.prevent="$wire.sendMessage()"
                        rows="1"
                        placeholder="Mensagem para o agente…"
                        class="flex-1 resize-none rounded-xl border-gray-300 text-sm dark:border-white/10 dark:bg-gray-800"
                    ></textarea>
                    <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                        Enviar
                    </x-filament::button>
                </div>
                @error('draft') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                @error('agentId') <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
            </form>
        </section>
    </div>

    <script>
        function playgroundChat(initialConversationId) {
            return {
                conversationId: initialConversationId,
                channel: null,
                live: { active: false, messageId: null, text: '', debug: [], status: null },

                init() {
                    this.subscribe(this.conversationId);

                    this.$wire.on('playground-conversation-changed', (e) => {
                        const id = Array.isArray(e) ? e[0]?.conversationId : e?.conversationId;
                        this.resetLive();
                        this.subscribe(id ?? null);
                    });

                    this.$wire.on('playground-message-pending', (e) => {
                        const data = Array.isArray(e) ? e[0] : e;
                        this.subscribe(data.conversationId);
                        this.live = { active: true, messageId: data.messageId, text: '', debug: [], status: 'streaming' };
                        this.scroll();
                    });
                },

                async waitForEcho() {
                    let tries = 0;
                    while (!window.Echo && tries < 50) {
                        await new Promise((r) => setTimeout(r, 100));
                        tries++;
                    }
                    return window.Echo;
                },

                async subscribe(conversationId) {
                    const echo = await this.waitForEcho();
                    if (!echo) return;

                    if (this.channel !== null && this.channel !== conversationId) {
                        echo.leave('playground.conversation.' + this.channel);
                    }

                    this.channel = conversationId;
                    if (conversationId === null || conversationId === undefined) return;

                    echo.private('playground.conversation.' + conversationId)
                        .listen('.stream', (e) => this.onStream(e));
                },

                onStream(e) {
                    if (this.live.messageId !== null && e.messageId !== this.live.messageId) return;

                    const p = e.payload || {};

                    switch (e.kind) {
                        case 'token':
                            this.live.active = true;
                            this.live.text += p.delta || '';
                            this.scroll();
                            break;
                        case 'routing':
                            this.live.debug.push({ label: 'roteamento → ', detail: 'especialista #' + (p.specialist_id ?? '—') + ' (conf ' + (p.confidence ?? '—') + ')' });
                            break;
                        case 'tool_call':
                            this.live.debug.push({ label: 'tool ▶ ' + (p.tool ?? '?') + ' ', detail: JSON.stringify(p.input ?? {}) });
                            break;
                        case 'tool_result':
                            this.live.debug.push({ label: 'tool ◀ ' + (p.tool ?? '?') + ' ', detail: String(p.output ?? '') });
                            break;
                        case 'completed':
                        case 'failed':
                            this.live.status = e.kind;
                            this.$wire.$refresh().then(() => this.resetLive());
                            break;
                    }
                },

                resetLive() {
                    this.live = { active: false, messageId: null, text: '', debug: [], status: null };
                },

                scroll() {
                    this.$nextTick(() => {
                        const el = this.$refs.transcript;
                        if (el) el.scrollTop = el.scrollHeight;
                    });
                },
            };
        }
    </script>
</x-filament-panels::page>
