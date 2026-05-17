<x-filament-panels::page>
    @php
        $connection = \App\Models\ChatwootPlatformConnection::current();
    @endphp

    <form wire:submit="save">
        {{ $this->form }}
    </form>

    @if ($connection->exists)
        <div class="mt-6 rounded-lg border border-gray-200 dark:border-gray-700 p-4 text-sm">
            <h3 class="font-semibold mb-2">Última sincronização</h3>

            @if ($connection->last_synced_at)
                <p>
                    <strong>Quando:</strong> {{ $connection->last_synced_at->diffForHumans() }}
                    ({{ $connection->last_synced_at->format('Y-m-d H:i:s') }})
                </p>
                <p>
                    <strong>Status:</strong>
                    <span class="@if($connection->last_sync_status === 'success') text-green-600 @else text-red-600 @endif">
                        {{ $connection->last_sync_status }}
                    </span>
                </p>

                @if ($connection->last_sync_error)
                    <p class="text-red-600"><strong>Erro:</strong> {{ $connection->last_sync_error }}</p>
                @endif

                @if ($connection->last_sync_summary)
                    <ul class="mt-2 list-disc pl-5">
                        @foreach ($connection->last_sync_summary as $key => $value)
                            <li><code>{{ $key }}</code>: {{ $value }}</li>
                        @endforeach
                    </ul>
                @endif
            @else
                <p class="text-gray-500">Nenhuma sincronização executada ainda.</p>
            @endif
        </div>
    @endif
</x-filament-panels::page>
