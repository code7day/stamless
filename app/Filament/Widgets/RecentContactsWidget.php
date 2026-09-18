<?php

namespace App\Filament\Widgets;

use App\Enums\UserRoleEnum;
use App\Filament\Concerns\RestrictsPageToRoles;
use App\Filament\Resources\ContactResource;
use App\Models\Contact;
use App\Models\Tenant;
use App\Support\FriendlyDate;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * 2026-09-13, "Nivel 2" del plan de Dashboard — pedido original del Tech
 * Lead: "que ahorre tiempo ubicar las cosas". Preview de los últimos leads
 * sin salir del Escritorio; "Ver todos" lleva a `ContactResource` completo
 * (con filtros/edición real, que este widget no duplica).
 */
class RecentContactsWidget extends TableWidget
{
    use RestrictsPageToRoles;

    /**
     * Columna 1 (default de `Widget`, explícita acá para que quede
     * documentado): comparte fila con `PlanUsageWidget` (también columna
     * 1) en la grilla de 2 columnas del Escritorio — ver su docblock.
     */
    protected int|string|array $columnSpan = 1;

    protected static ?int $sort = -10;

    /**
     * 2026-09-18, mismo fix (y misma corrección de 2do round) que
     * `LeadsOverviewWidget` (ver su docblock completo) — este widget
     * mostraba una previsualización real de `Contact` sin chequear ninguna
     * Policy, visible a cualquier rol con acceso al Dashboard. El gate
     * base es `viewAny` de `Contact` (el Tech Lead corrigió el primer
     * intento, que exigía Contactos Y Formularios a la vez y sin querer
     * excluía a `Editor`): "el rol editor tiene acceso a contactos, podría
     * ver los 5 widgets de contactos".
     *
     * **3er round, mismo día**: pedido explícito del Tech Lead — "para el
     * rol soporte no mostrar el widget últimos contactos". A diferencia de
     * `LeadsOverviewWidget` (los 4 KPIs, que Soporte SÍ sigue viendo, no
     * mencionados en este pedido), acá se excluye a `Soporte` puntualmente
     * aunque la Policy de `Contact` le siga dando acceso de lectura real
     * (`ContactResource` completo, con "Ver todos" desde este mismo
     * widget, sigue intacto para Soporte) — es una decisión de qué
     * previsualizar en el Escritorio, no de permisos. `RestrictsPageToRoles
     * ::userHasAnyRole()` (mismo trait que usan las Pages sin Eloquent)
     * para el chequeo de rol puntual.
     */
    public static function canView(): bool
    {
        if (! Filament::getTenant() instanceof Tenant) {
            return false;
        }

        if (self::userHasAnyRole([UserRoleEnum::Soporte->value])) {
            return false;
        }

        return (bool) auth()->user()?->can('viewAny', Contact::class);
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();

        return $table
            ->heading('Últimos contactos')
            ->query(
                Contact::query()
                    ->when($tenant instanceof Tenant, fn ($query) => $query->where('tenant_id', $tenant->id))
                    ->latest('created_at')
                    ->limit(5)
            )
            // 2026-09-18, pedido del Tech Lead: "desde últimos contactos
            // debería poder darse clic en el contacto y abrir el formulario
            // para atenderlo" — la fila entera ahora es clickeable y abre
            // directo el `EditAction` ("Ver / gestionar", slide-over) de
            // `ContactResource`, mismo deep-link (`tableAction`/
            // `tableActionRecord`) que `RecentContentChangesWidget`. Con
            // `->getKey()` explícito, NO el modelo — pasar el modelo
            // sustituye por su route key (`uuid` en esta app, `HasUuid`),
            // pero Filament resuelve la acción de tabla montada por la PK
            // interna (`id`, bigint) vía `resolveRecordKey()`, mismo bug ya
            // encontrado y corregido en `RecentContentChangesWidget`.
            ->recordUrl(fn (Contact $record): string => ContactResource::getUrl(parameters: [
                'tableAction' => 'edit',
                'tableActionRecord' => $record->getKey(),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->weight('bold')
                    ->description(fn (Contact $record): ?string => $record->email)
                    ->default('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn ($state) => match ($state?->value) {
                        'new' => 'info',
                        'in_progress' => 'warning',
                        'closed' => 'success',
                        'spam' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Recibido')
                    ->formatStateUsing(fn (Contact $record): ?string => FriendlyDate::format($record->created_at)),
            ])
            ->headerActions([
                Actions\Action::make('viewAll')
                    ->label('Ver todos')
                    ->icon('heroicon-m-arrow-right')
                    ->url(ContactResource::getUrl()),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated(false)
            ->emptyStateHeading('Todavía no llegó ningún contacto')
            ->emptyStateDescription('Acá van a aparecer los últimos leads que dejen sus datos en los formularios del sitio.');
    }
}
