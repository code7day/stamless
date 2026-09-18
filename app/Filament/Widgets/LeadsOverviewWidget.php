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

    /**
     * 2026-09-18, pedido del Tech Lead con captura: este widget (y
     * `RecentContactsWidget`, mismo caso) mostraba datos reales de
     * `Contact` a CUALQUIER rol con acceso al Dashboard, sin chequear
     * ninguna Policy — un `Author`/Redactor (sin acceso a Contactos en la
     * matriz de ADR-078) veía igual los 4 KPIs y la previsualización de
     * leads acá.
     *
     * **Corrección del mismo Tech Lead, 2do round**: el primer intento de
     * fix exigía `viewAny` de `Contact` Y de `Form` a la vez, lo que sin
     * querer excluía a `Editor` (tiene Contactos pero no Formularios) de
     * este resumen del Dashboard. El Tech Lead, viendo el Escritorio
     * logueado como Editor, aclaró el criterio real: "el rol editor tiene
     * acceso a contactos, podría ver los 5 widgets de contactos" — el gate
     * correcto es solo `Contact` (de donde sale TODO el dato que estos
     * widgets muestran: leads/contactos), sin exigir Formularios. Con la
     * matriz de 5 roles esto deja ver los 5 widgets a Admin/Soporte/
     * Marketing/Editor, y los sigue ocultando solo a `Author`.
     */
    public static function canView(): bool
    {
        if (! Filament::getTenant() instanceof Tenant) {
            return false;
        }

        return (bool) auth()->user()?->can('viewAny', Contact::class);
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
