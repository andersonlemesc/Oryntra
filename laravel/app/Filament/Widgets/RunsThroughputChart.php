<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class RunsThroughputChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Throughput por hora (24h)';

    protected ?string $maxHeight = '300px';

    private const STATUSES = ['completed', 'failed', 'waiting_human'];

    private const COLORS = [
        'completed' => 'rgb(34,197,94)',
        'failed' => 'rgb(239,68,68)',
        'waiting_human' => 'rgb(234,179,8)',
    ];

    private const LABELS = [
        'completed' => 'Concluidas',
        'failed' => 'Falhas',
        'waiting_human' => 'Aguardando humano',
    ];

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $tenantId = $this->tenantId();
        $now = now()->minute(0)->second(0);
        $from = $now->copy()->subHours(23);

        $buckets = $this->buildBuckets($tenantId, $from);
        $labels = [];
        $datasets = [];

        foreach (self::STATUSES as $status) {
            $datasets[$status] = [
                'label' => self::LABELS[$status],
                'data' => [],
                'borderColor' => self::COLORS[$status],
                'backgroundColor' => self::COLORS[$status],
                'tension' => 0.3,
            ];
        }

        for ($hour = 0; $hour < 24; $hour++) {
            $bucket = $from->copy()->addHours($hour);
            $key = $bucket->format('Y-m-d H:00');
            $labels[] = $bucket->format('H:00');

            foreach (self::STATUSES as $status) {
                $datasets[$status]['data'][] = $buckets[$key][$status] ?? 0;
            }
        }

        return [
            'labels' => $labels,
            'datasets' => array_values($datasets),
        ];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function buildBuckets(?int $tenantId, Carbon $from): array
    {
        if ($tenantId === null) {
            return [];
        }

        $rows = AgentRun::query()
            ->where('workspace_id', $tenantId)
            ->whereIn('status', self::STATUSES)
            ->where('started_at', '>=', $from)
            ->get(['started_at', 'status']);

        $buckets = [];
        foreach ($rows as $row) {
            $startedAt = $row->started_at;
            if (! $startedAt instanceof Carbon) {
                continue;
            }
            $key = $startedAt->copy()->minute(0)->second(0)->format('Y-m-d H:00');
            $status = $row->status instanceof AgentRunStatus ? $row->status->value : (string) $row->status;
            $buckets[$key][$status] = ($buckets[$key][$status] ?? 0) + 1;
        }

        return $buckets;
    }

    private function tenantId(): ?int
    {
        $tenant = Filament::getTenant();

        return $tenant === null ? null : (int) $tenant->getKey();
    }
}
