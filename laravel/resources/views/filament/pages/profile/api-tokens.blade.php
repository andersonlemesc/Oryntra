<x-filament-panels::page>
    @if ($plainTextToken)
        <div class="rounded-lg border border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/40 p-4">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h3 class="font-semibold text-amber-900 dark:text-amber-200">Seu novo token</h3>
                    <p class="text-sm text-amber-800 dark:text-amber-300 mb-2">
                        Copie agora. Por segurança, ele não será exibido novamente.
                    </p>
                    <code class="block break-all rounded bg-white dark:bg-gray-900 px-3 py-2 text-sm font-mono select-all">{{ $plainTextToken }}</code>

                    <div class="mt-3 text-xs text-amber-800 dark:text-amber-300">
                        <p class="mb-1 font-medium">Conectar via MCP:</p>
                        <pre class="overflow-x-auto rounded bg-white dark:bg-gray-900 px-3 py-2 font-mono text-xs leading-relaxed"><code class="select-all whitespace-pre">claude mcp add oryntra \
  --env ORYNTRA_API_URL={{ url('/api/v1') }} \
  --env ORYNTRA_API_TOKEN={{ $plainTextToken }} \
  -- npx -y @oryntra/mcp</code></pre>
                    </div>
                </div>
                <x-filament::button color="gray" size="sm" wire:click="dismissPlainTextToken">
                    Ok, copiei
                </x-filament::button>
            </div>
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 dark:border-white/10 divide-y divide-gray-100 dark:divide-white/5">
        @forelse ($this->getTokens() as $token)
            <div class="flex items-center justify-between gap-4 p-4">
                <div class="min-w-0">
                    <p class="font-medium truncate">{{ $token->name }}</p>
                    <p class="text-sm text-gray-500">
                        <span class="font-medium">{{ $token->workspace?->name ?? '—' }}</span>
                        · {{ count($token->abilities ?? []) }} permissões
                        @if ($token->last_used_at)
                            · usado {{ $token->last_used_at->diffForHumans() }}
                        @else
                            · nunca usado
                        @endif
                    </p>
                    <div class="mt-1 flex flex-wrap gap-1">
                        @foreach ($token->abilities ?? [] as $ability)
                            <span class="inline-flex rounded bg-gray-100 dark:bg-white/10 px-1.5 py-0.5 text-[11px] font-mono">{{ $ability }}</span>
                        @endforeach
                    </div>
                </div>
                {{ ($this->revokeTokenAction)(['token' => $token->id]) }}
            </div>
        @empty
            <div class="px-4 py-3 text-sm text-gray-500">
                Nenhum token ainda. Gere um para conectar o servidor MCP da Oryntra.
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
