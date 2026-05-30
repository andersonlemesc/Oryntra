@props(['message'])

@php
    /** @var \App\Models\PlaygroundMessage $message */
    $events = is_array($message->events) ? $message->events : [];
    $trace = is_array($message->trace) ? $message->trace : [];
    $usage = is_array($message->usage) ? $message->usage : [];
    $cost = data_get($usage, 'total_cost_cents');

    // Build an ordered timeline. Prefer the streamed debug events (routing +
    // tool calls/results, in real-time order); fall back to the runtime trace.
    $timeline = [];

    foreach ($events as $event) {
        $kind = data_get($event, 'kind');
        $p = is_array(data_get($event, 'payload')) ? data_get($event, 'payload') : [];

        if ($kind === 'routing') {
            $timeline[] = [
                'icon' => 'heroicon-o-arrows-right-left',
                'color' => 'text-primary-500',
                'title' => 'Roteado para especialista #'.data_get($p, 'specialist_id', '—'),
                'meta' => 'confiança '.data_get($p, 'confidence', '—').' · '.data_get($p, 'reason', ''),
                'body' => null,
            ];
        } elseif ($kind === 'tool_call') {
            $timeline[] = [
                'icon' => 'heroicon-o-wrench-screwdriver',
                'color' => 'text-amber-500',
                'title' => 'Tool ▶ '.data_get($p, 'tool', '?'),
                'meta' => null,
                'body' => json_encode(data_get($p, 'input', []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ];
        } elseif ($kind === 'tool_result') {
            $timeline[] = [
                'icon' => 'heroicon-o-check-circle',
                'color' => 'text-success-500',
                'title' => 'Tool ◀ '.data_get($p, 'tool', '?'),
                'meta' => null,
                'body' => (string) data_get($p, 'output', ''),
            ];
        }
    }

    if ($timeline === []) {
        foreach ($trace as $step) {
            $timeline[] = [
                'icon' => 'heroicon-o-cpu-chip',
                'color' => 'text-gray-400',
                'title' => data_get($step, 'type', 'step'),
                'meta' => data_get($step, 'latency_ms') ? data_get($step, 'latency_ms').'ms' : null,
                'body' => filled(data_get($step, 'output')) ? json_encode(data_get($step, 'output'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : null,
            ];
        }
    }
@endphp

@if ($timeline !== [])
    <div class="mt-1 max-w-[85%] rounded-xl bg-gray-50 p-3 text-xs ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        <div class="mb-2 flex items-center gap-2 text-[11px] font-medium text-gray-500">
            <x-filament::icon icon="heroicon-o-bolt" class="h-3.5 w-3.5" />
            <span>Linha do tempo do agente</span>
            @if ($message->specialist_id !== null)
                <span class="rounded bg-gray-200 px-1.5 py-0.5 font-mono text-[10px] text-gray-600 dark:bg-white/10 dark:text-gray-300">esp #{{ $message->specialist_id }}</span>
            @endif
            @if ($cost !== null)
                <span class="ml-auto font-mono text-[10px] text-gray-400">{{ number_format(((int) $cost) / 100, 2) }}¢</span>
            @endif
        </div>

        <ol class="relative space-y-3 border-l border-gray-200 pl-4 dark:border-white/10">
            @foreach ($timeline as $item)
                <li class="relative">
                    <span class="absolute -left-[1.30rem] flex h-4 w-4 items-center justify-center rounded-full bg-white ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                        <x-filament::icon :icon="$item['icon']" @class(['h-3 w-3', $item['color']]) />
                    </span>
                    <div class="font-medium text-gray-700 dark:text-gray-200">{{ $item['title'] }}</div>
                    @if ($item['meta'])
                        <div class="text-[11px] text-gray-400">{{ $item['meta'] }}</div>
                    @endif
                    @if ($item['body'])
                        <pre class="mt-1 overflow-x-auto rounded bg-white/70 p-1.5 text-[10px] leading-snug text-gray-500 dark:bg-black/20">{{ $item['body'] }}</pre>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
@endif
