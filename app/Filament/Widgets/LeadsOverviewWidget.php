<?php

namespace App\Filament\Widgets;

use App\Enums\ContactStatusEnum;
use App\Filament\Resources\ContactResource;
use App\Models\Contact;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 2026-09-13, "Nivel 2" del plan de Dashboard — pedido original del Tech
 * Lead: "totales de contactos o leads... KPI's comunes que podrian tener
 * los usuarios para motivarse y tomar mejores decisiones". 3 stats simples
 * (sin gráfico de tendencia — no hay todavía una serie histórica que
 * mostrar, ver `ContactActivity`/`created_at` como única fuente de fecha):
 * nuevos sin atender, en proceso, y total + cuántos llegaron esta semana.
 */
class LeadsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -30;

    /**
     * @var int | array<string, ?int> | null
     */
    protected int|array|null $columns = [
        'default' => 2,
        'sm' => 2,
        'md' => 4,
    ];

    public static function canView(): bool
    {
        return Filament::getTenant() instanceof Tenant;
    }

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            return [];
        }

        $query = fn () => Contact::where('tenant_id', $tenant->id);

        $newCount = $query()->where('status', ContactStatusEnum::New->value)->count();
        $inProgressCount = $query()->where('status', ContactStatusEnum::InProgress->value)->count();
        $closedCount = $query()->where('status', ContactStatusEnum::Closed->value)->count();
        $totalCount = $query()->count();
        $thisWeekCount = $query()->where('created_at', '>=', now()->subDays(7))->count();

        return [
            Stat::make('Leads nuevos', $newCount)
                ->description('Sin atender todavía')
                ->descriptionIcon('heroicon-m-bell-alert')
                ->color($newCount > 0 ? 'danger' : 'gray')
                ->url(ContactResource::getUrl()),

            Stat::make('En proceso', $inProgressCount)
                ->description('Contactos en seguimiento')
                ->descriptionIcon('heroicon-m-clock')
                ->color($inProgressCount > 0 ? 'warning' : 'gray')
                ->url(ContactResource::getUrl()),

            Stat::make('Atendidos', $closedCount)
                ->description('Contactos resueltos')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color($closedCount > 0 ? 'success' : 'gray')
                ->url(ContactResource::getUrl()),

            Stat::make('Total de contactos', $totalCount)
                ->description("{$thisWeekCount} en los últimos 7 días")
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('gray')
                ->url(ContactResource::getUrl()),
        ];
    }
}
