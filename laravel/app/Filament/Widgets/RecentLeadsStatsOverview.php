<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Contact;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class RecentLeadsStatsOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Leads recentes';

    protected static ?int $sort = 5;

    protected function getStats(): array
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return [];
        }

        $now = Carbon::now();
        $since24h = $now->copy()->subDay();
        $since48h = $now->copy()->subDays(2);

        $newCurrent = $this->countNewLeads($tenantId, $since24h, $now);
        $newPrevious = $this->countNewLeads($tenantId, $since48h, $since24h);

        $qualifiedTotal = Contact::query()
            ->where('workspace_id', $tenantId)
            ->where('lead_status', 'qualified')
            ->count();

        $wonTotal = Contact::query()
            ->where('workspace_id', $tenantId)
            ->where('lead_status', 'won')
            ->count();

        return [
            Stat::make('Novos leads (24h)', (string) $newCurrent)
                ->description($this->describeDelta($newCurrent - $newPrevious, 'vs 24h anteriores'))
                ->color($newCurrent > 0 ? 'primary' : 'gray'),
            Stat::make('Qualificados', (string) $qualifiedTotal)
                ->color($qualifiedTotal > 0 ? 'warning' : 'gray')
                ->description('Total no workspace'),
            Stat::make('Convertidos', (string) $wonTotal)
                ->color($wonTotal > 0 ? 'success' : 'gray')
                ->description('Total no workspace'),
        ];
    }

    private function countNewLeads(int $tenantId, Carbon $from, Carbon $to): int
    {
        return Contact::query()
            ->where('workspace_id', $tenantId)
            ->where('first_seen_at', '>=', $from)
            ->where('first_seen_at', '<', $to)
            ->count();
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
