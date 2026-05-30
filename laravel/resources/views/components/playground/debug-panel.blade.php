@props(['message'])

@php
    /** @var \App\Models\PlaygroundMessage $message */
    $trace = is_array($message->trace) ? $message->trace : [];
    $usage = is_array($message->usage) ? $message->usage : [];
    $cost = data_get($usage, 'total_cost_cents');
@endphp

<details class="max-w-[80%] rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-white/5">
    <summary class="flex cursor-pointer items-center gap-2 font-medium text-gray-500">
        <x-filament::icon icon="heroicon-o-bug-ant" class="h-3.5 w-3.5" />
        Debug
        @if ($message->specialist_id !== null)
            <span class="rounded bg-gray-200 px-1.5 py-0.5 font-mono text-[10px] text-gray-600 dark:bg-white/10 dark:text-gray-300">
                especialista #{{ $message->specialist_id }}
            </span>
        @endif
        @if ($cost !== null)
            <span class="ml-auto font-mono text-[10px] text-gray-400">{{ number_format(((int) $cost) / 100, 2) }}¢</span>
        @endif
    </summary>

    <ul class="mt-2 space-y-2">
        @foreach ($trace as $step)
            @php
                $type = data_get($step, 'type', 'step');
                $tool = data_get($step, 'tool');
                $input = data_get($step, 'input');
                $output = data_get($step, 'output');
                $latency = data_get($step, 'latency_ms');
                $tokensIn = data_get($step, 'tokens.input');
                $tokensOut = data_get($step, 'tokens.output');
            @endphp

            <li class="border-l-2 border-gray-200 pl-2 dark:border-white/10">
                <div class="flex items-center gap-2 font-mono text-[11px] font-semibold text-gray-600 dark:text-gray-300">
                    <span>{{ data_get($step, 'step', '·') }}.</span>
                    <span>{{ $type }}</span>
                    @if ($tool)
                        <span class="rounded bg-primary-100 px-1 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">{{ $tool }}</span>
                    @endif
                    @if ($latency)
                        <span class="ml-auto text-gray-400">{{ $latency }}ms</span>
                    @endif
                    @if ($tokensIn || $tokensOut)
                        <span class="text-gray-400">{{ $tokensIn ?? 0 }}↓/{{ $tokensOut ?? 0 }}↑</span>
                    @endif
                </div>

                @if (filled($input))
                    <pre class="mt-1 overflow-x-auto rounded bg-white/60 p-1 text-[10px] text-gray-500 dark:bg-black/20">{{ json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</pre>
                @endif
                @if (filled($output))
                    <pre class="mt-1 overflow-x-auto rounded bg-white/60 p-1 text-[10px] text-gray-500 dark:bg-black/20">{{ is_string($output) ? $output : json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</pre>
                @endif
            </li>
        @endforeach
    </ul>
</details>
