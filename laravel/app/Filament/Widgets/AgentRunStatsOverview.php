<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class AgentRunStatsOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Execuções nas últimas 24 horas';

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return [];
        }

        $now = now();
        $since24h = $now->copy()->subDay();
        $since48h = $now->copy()->subDays(2);

        $current = $this->countsByStatus($tenantId, $since24h, $now);
        $previous = $this->countsByStatus($tenantId, $since48h, $since24h);

        $totalCurrent = array_sum($current);
        $totalPrevious = array_sum($previous);
        $completedDelta = ($current['completed'] ?? 0) - ($previous['completed'] ?? 0);

        return [
            Stat::make('Total', (string) $totalCurrent)
                ->description($this->describeDelta($totalCurrent - $totalPrevious, 'execuções'))
                ->color($totalCurrent > 0 ? 'primary' : 'gray'),
            Stat::make('Concluidas', (string) ($current['completed'] ?? 0))
                ->description($this->describeDelta($completedDelta, 'vs 24h anteriores'))
                ->color('success'),
            Stat::make('Aguardando humano', (string) ($current['waiting_human'] ?? 0))
                ->color(($current['waiting_human'] ?? 0) > 0 ? 'warning' : 'gray')
                ->description('Precisam revisao manual'),
            Stat::make('Falhas', (string) ($current['failed'] ?? 0))
                ->color(($current['failed'] ?? 0) > 0 ? 'danger' : 'gray')
                ->description('Erros nas ultimas 24h'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function countsByStatus(int $tenantId, Carbon $from, Carbon $to): array
    {
        /** @var array<int, object{status:string,total:int}> $rows */
        $rows = AgentRun::query()
            ->fromChatwoot()
            ->where('workspace_id', $tenantId)
            ->where('started_at', '>=', $from)
            ->where('started_at', '<', $to)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get()
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $status = $row->status;
            $key = $status instanceof AgentRunStatus
                ? $status->value
                : (string) $status;
            $result[$key] = (int) $row->total;
        }

        return $result;
    }

    private function describeDelta(int $delta, string $suffix): string
    {
        if ($delta === 0) {
            return "Sem mudanca {$suffix}";
        }

        $arrow = $delta > 0 ? '↑' : '↓';

        return sprintf('%s %d %s', $arrow, abs($delta), $suffix);
    }

    private function tenantId(): ?int
    {
        $tenant = Filament::getTenant();

        return $tenant === null ? null : (int) $tenant->getKey();
    }
}
